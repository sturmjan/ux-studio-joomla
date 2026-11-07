<?php

namespace Ux1\UxStudio\Modules\ServiceRequests;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Version;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\CaConnector\CaClient;

\defined('_JEXEC') or die;

/**
 * Outbound queue and inbound mirror of the Central App ticket system (page "ticket_api").
 *
 * The Central App owns a request: its number, status and conversation come from there. Locally a row is kept so the
 * administrator sees their requests even when the Central App is unreachable. Every write is stored first, pushed at
 * once as a best effort and retried with backoff by the scheduler task. Permanent failures (HTTP 4xx other than 429:
 * unknown site, wrong key, invalid payload) stop retrying and stay visible as sync_state "error" with the reason.
 * The external id ("uxs-<local id>") makes every retry idempotent on the Central App side.
 */
final class Sync
{
    /** After this many failed attempts a request / reply is no longer retried automatically. */
    public const MAX_ATTEMPTS = 8;

    /** Exception code of a refusal that arrived with HTTP 200 and success = false. */
    private const REFUSED = 422;

    /** Seconds to wait after the n-th failed attempt. */
    private const BACKOFF = [0, 60, 300, 900, 3600, 10800, 21600, 43200];

    /** Central status => the three-state column kept for the legacy list. */
    private const LOCAL_STATUS = [
        'new'            => 'open',
        'triaged'        => 'open',
        'in_progress'    => 'in_progress',
        'waiting_client' => 'in_progress',
        'waiting_vendor' => 'in_progress',
        'resolved'       => 'done',
        'closed'         => 'done',
        'rejected'       => 'done',
    ];

    /** Reason of the last failed call in this request, so the manual "refresh" can tell the user. */
    public ?string $lastError = null;

    public function __construct(private ModuleContext $ctx, private Store $store)
    {
    }

    /** True when the site is paired with a Central App and the connector is on. */
    public function isConfigured(): bool
    {
        return $this->client() !== null;
    }

    private function client(): ?CaClient
    {
        if (!class_exists(CaClient::class)) {
            return null;
        }
        try {
            return CaClient::fromContext($this->ctx);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function externalId(int $requestId): string
    {
        return 'uxs-' . $requestId;
    }

    /** One scheduler pass. @return array{pushed:int,replies:int,pulled:int} */
    public function run(): array
    {
        $out = ['pushed' => 0, 'replies' => 0, 'pulled' => 0];
        if (!$this->isConfigured()) {
            return $out;
        }
        $out['pushed']  = $this->pushPending(20);
        $out['replies'] = $this->flushOutbox(40);
        $out['pulled']  = $this->pullUpdates(30);

        return $out;
    }

    /* ---------------------------------------------------------------- push */

    public function pushPending(int $limit): int
    {
        $done = 0;
        foreach ($this->store->unsynced(self::MAX_ATTEMPTS, max(1, min(50, $limit))) as $row) {
            if ($this->isDue((int) $row['sync_attempts'], (string) ($row['last_try_at'] ?? '')) && $this->pushOne($row)) {
                $done++;
            }
        }

        return $done;
    }

    /** @param array<string,mixed> $row raw request row @return bool true when the Central App accepted it */
    public function pushOne(array $row): bool
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }
        $id = (int) $row['id'];
        try {
            $env    = json_decode((string) ($row['environment'] ?? ''), true);
            $result = $client->post('ticket_api', ['action' => 'create'], [
                'external_id'          => self::externalId($id),
                'subject'              => (string) $row['title'],
                'description'          => (string) ($row['description'] ?? ''),
                'url'                  => (string) ($row['page_url'] ?? ''),
                'type'                 => (string) ($row['type'] ?? 'bug'),
                'priority'             => (string) ($row['priority'] ?? 'normal'),
                'requester_name'       => (string) ($row['requester_name'] ?? ''),
                'requester_email'      => (string) ($row['requester_email'] ?? ''),
                'requester_wp_user_id' => (int) ($row['requester_user_id'] ?? 0),
                'environment'          => \is_array($env) ? $env : [],
            ]);
            if (empty($result['success'])) {
                // A well-formed refusal (success = false) describes THIS payload: repeating it never helps.
                throw new \RuntimeException((string) ($result['error'] ?? 'Central App refused the request.'), self::REFUSED);
            }
        } catch (\Throwable $e) {
            $this->recordFailure($id, (int) $row['sync_attempts'], $e);

            return false;
        }

        $ticket = \is_array($result['ticket'] ?? null) ? $result['ticket'] : [];
        $this->store->update($id, [
            'central_ticket_id' => (int) ($ticket['id'] ?? 0),
            'central_number'    => mb_substr((string) ($ticket['number'] ?? ''), 0, 20),
            'central_status'    => mb_substr((string) ($ticket['status'] ?? ''), 0, 20),
            'status'            => self::LOCAL_STATUS[(string) ($ticket['status'] ?? '')] ?? 'open',
            'sync_state'        => 'synced',
            'sync_attempts'     => 0,
            'sync_error'        => '',
            'synced_at'         => Factory::getDate()->toSql(),
        ]);

        return true;
    }

    private function recordFailure(int $id, int $attempts, \Throwable $e): void
    {
        $next    = $attempts + 1;
        $perm    = self::isPermanent($e);
        $dead    = $perm || $next >= self::MAX_ATTEMPTS;
        $message = $e->getMessage();
        if ($dead && !$perm) {
            // Out of automatic attempts: show it as "not delivered" instead of "sending" forever; "refresh" starts over.
            $message = sprintf('Gave up after %d attempts: %s', self::MAX_ATTEMPTS, $message);
        }
        $this->lastError = $message;
        $this->store->update($id, [
            'sync_attempts' => $dead ? self::MAX_ATTEMPTS : $next,
            'sync_state'    => $dead ? 'error' : 'pending',
            'sync_error'    => mb_substr($message, 0, 500),
            'last_try_at'   => Factory::getDate()->toSql(),
        ]);
        Log::add('service-requests: push of request ' . $id . ' failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
    }

    /**
     * A 4xx other than 429 / 408 / 401 / 403 / 404 is a problem with THIS request (bad payload): retrying never helps.
     * 401 / 403 / 404 are pairing problems (rotated key, site not registered yet) that the operator fixes later, so they
     * go through the normal backoff instead of condemning the message on the first try.
     */
    private static function isPermanent(\Throwable $e): bool
    {
        if ($e->getCode() === self::REFUSED) {
            return true;
        }
        if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $m)) {
            $code = (int) $m[1];

            return $code >= 400 && $code < 500 && !\in_array($code, [429, 408, 401, 403, 404], true);
        }

        return false;
    }

    /** Exponential-ish backoff on the time of the last failed attempt (UTC). */
    private function isDue(int $attempts, string $lastTry): bool
    {
        if ($attempts <= 0 || $lastTry === '') {
            return true;
        }
        $last = strtotime($lastTry . ' UTC');
        if ($last === false) {
            return true;
        }
        $wait = self::BACKOFF[min($attempts, \count(self::BACKOFF) - 1)];

        return (time() - $last) >= $wait;
    }

    /* ---------------------------------------------------------------- replies */

    /** Stores the reply in the outbox and tries to deliver it right away. */
    public function queueReply(int $requestId, string $body, string $name, string $email): void
    {
        $this->store->queueReply($requestId, 'msg-' . $requestId . '-' . bin2hex(random_bytes(4)), $body, $name, $email);
        $this->flushOutbox(5);
    }

    public function flushOutbox(int $limit): int
    {
        $client = $this->client();
        if ($client === null) {
            return 0;
        }
        $sent = 0;
        foreach ($this->store->outbox(self::MAX_ATTEMPTS, max(1, min(100, $limit))) as $row) {
            // A reply cannot arrive before its ticket exists; it stays queued while the create is being retried.
            if ((int) $row['central_ticket_id'] <= 0 || !$this->isDue((int) $row['attempts'], (string) ($row['last_try_at'] ?? ''))) {
                continue;
            }
            try {
                $result = $client->post('ticket_api', ['action' => 'message'], [
                    'external_id'         => self::externalId((int) $row['request_id']),
                    'external_message_id' => (string) $row['external_id'],
                    'body'                => (string) $row['body'],
                    'author_name'         => (string) $row['author_name'],
                    'author_email'        => (string) $row['author_email'],
                ]);
                if (empty($result['success'])) {
                    throw new \RuntimeException((string) ($result['error'] ?? 'Central App refused the message.'), self::REFUSED);
                }
            } catch (\Throwable $e) {
                $this->store->outboxFailed((int) $row['id'], self::isPermanent($e) ? self::MAX_ATTEMPTS : (int) $row['attempts'] + 1, $e->getMessage());
                $this->lastError = $e->getMessage();
                continue;
            }
            // Delivered: the message now lives in the central thread, which is mirrored on the next pull.
            $this->store->outboxDelete((int) $row['id']);
            $sent++;
        }

        return $sent;
    }

    /* ---------------------------------------------------------------- pull */

    public function pullUpdates(int $limit): int
    {
        $done = 0;
        foreach ($this->store->openIds(max(1, min(50, $limit))) as $id) {
            if ($this->pullOne($id)) {
                $done++;
            }
        }

        return $done;
    }

    public function pullOne(int $requestId): bool
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }
        try {
            $result = $client->get('ticket_api', ['action' => 'detail', 'external_id' => self::externalId($requestId)]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
        if (empty($result['success'])) {
            $this->lastError = (string) ($result['error'] ?? 'Central App refused the request.');

            return false;
        }
        $ticket  = \is_array($result['ticket'] ?? null) ? $result['ticket'] : [];
        $central = (string) ($ticket['status'] ?? '');
        $thread  = [];
        foreach ((array) ($result['messages'] ?? []) as $m) {
            if (!\is_array($m)) {
                continue;
            }
            $thread[] = [
                'id'          => (int) ($m['id'] ?? 0),
                'author'      => mb_substr((string) ($m['author'] ?? ''), 0, 120),
                'author_kind' => (string) ($m['author_kind'] ?? ''),
                'body'        => mb_substr((string) ($m['body'] ?? ''), 0, 20000),
                'created_at'  => (string) ($m['created_at'] ?? ''),
            ];
        }
        $this->store->update($requestId, [
            'central_status' => mb_substr($central, 0, 20),
            'central_number' => mb_substr((string) ($ticket['number'] ?? ''), 0, 20),
            'central_thread' => (string) json_encode($thread, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'         => self::LOCAL_STATUS[$central] ?? 'open',
            'sync_state'     => 'synced',
            'sync_error'     => '',
            'synced_at'      => Factory::getDate()->toSql(),
        ]);

        return true;
    }

    /** Pressing "refresh" means the cause of an earlier failure was dealt with, so the backoff must not keep the row waiting. */
    public function resetAndPush(int $requestId): void
    {
        $this->store->outboxReset($requestId);
        $this->store->update($requestId, ['sync_attempts' => 0, 'sync_state' => 'pending', 'sync_error' => '']);
        $row = $this->store->row($requestId);
        if ($row !== null) {
            $this->pushOne($row); // a failure lands in lastError via recordFailure()
        }
    }

    /* ---------------------------------------------------------------- environment */

    /**
     * What was true on this site when the request was filed (collected once, at submit time). Keys are plain labels:
     * the Central App flattens them to "label => text".
     *
     * @return array<string,string>
     */
    public function collectEnvironment(string $pageUrl, \Joomla\CMS\User\User $user): array
    {
        $db  = $this->ctx->db();
        $app = $this->ctx->app();

        $template = '';
        try {
            $client   = 0;
            $template = (string) $db->setQuery(
                $db->createQuery()->select($db->quoteName('template'))->from($db->quoteName('#__template_styles'))
                    ->where($db->quoteName('client_id') . ' = :c')->where($db->quoteName('home') . ' = ' . $db->quote('1'))->bind(':c', $client, ParameterType::INTEGER)
            )->loadResult();
        } catch (\Throwable $e) {
            // optional
        }

        $extensions = [];
        try {
            $rows = $db->setQuery(
                $db->createQuery()->select([$db->quoteName('element'), $db->quoteName('type'), $db->quoteName('folder'), $db->quoteName('client_id'), $db->quoteName('manifest_cache')])->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('enabled') . ' = 1')->where($db->quoteName('protected') . ' = 0')
                    ->where($db->quoteName('type') . ' IN (' . implode(',', array_map([$db, 'quote'], ['component', 'plugin', 'module', 'library'])) . ')')
                    ->order($db->quoteName('extension_id') . ' DESC'),
                0,
                400
            )->loadAssocList() ?: [];
            foreach ($rows as $r) {
                $manifest = json_decode((string) $r['manifest_cache'], true);
                if (\is_array($manifest) && stripos((string) ($manifest['author'] ?? ''), 'Joomla! Project') !== false) {
                    continue; // core extension: the Joomla version already says it
                }
                if (self::isCore((string) $r['type'], (string) $r['element'], (int) $r['client_id'], (string) $r['folder'])) {
                    continue;
                }
                $version = \is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
                // The element (com_x, plg_group_x, mod_x) is what an operator searches for; the translated name is not stable.
                $label = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', self::label((string) $r['type'], (string) $r['element'], (string) $r['folder']));
                if ($label !== '') {
                    $extensions[] = trim($label . ' ' . $version);
                }
                if (\count($extensions) >= 30) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // optional
        }

        $env = [
            'Page'       => $pageUrl,
            'Joomla'     => (new Version())->getShortVersion(),
            'PHP'        => PHP_VERSION,
            'Template'   => $template,
            'Language'   => (string) $app->getLanguage()->getTag(),
            'User group' => implode(', ', array_map('strval', $this->groupTitles($user))),
            'Browser'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'Extensions' => implode(', ', $extensions),
        ];

        return array_filter($env, static fn (string $v): bool => trim($v) !== '');
    }

    private static function isCore(string $type, string $element, int $clientId, string $folder): bool
    {
        try {
            return \Joomla\CMS\Extension\ExtensionHelper::checkIfCoreExtension($type, $element, $clientId, $folder);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function label(string $type, string $element, string $folder): string
    {
        return match ($type) {
            'plugin'  => 'plg_' . $folder . '_' . $element,
            'library' => 'lib_' . $element,
            default   => $element,
        };
    }

    /** @return string[] */
    private function groupTitles(\Joomla\CMS\User\User $user): array
    {
        $ids = array_map('intval', array_values((array) $user->groups));
        if (!$ids) {
            return [];
        }
        $db = $this->ctx->db();
        try {
            return array_map('strval', $db->setQuery(
                $db->createQuery()->select($db->quoteName('title'))->from($db->quoteName('#__usergroups'))->whereIn($db->quoteName('id'), $ids)
            )->loadColumn() ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

<?php

namespace Ux1\UxStudio\Modules\ServiceRequests;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Mail;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Service requests: the site administrator files a request (ticket) which is sent to the Central App, follows its
 * status and replies to the conversation. Port of the WordPress ServiceRequests module, transport via CaClient
 * (ticket_api). Works without the Central App too: requests stay "pending" locally and a notification e-mail is sent.
 */
final class Module extends BaseModule
{
    private const STATUSES   = ['open', 'in_progress', 'done'];
    /** What a client may pick; "incident" is an operator's classification. */
    private const TYPES      = ['bug', 'change', 'question', 'task'];
    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function boot(ModuleContext $ctx): void
    {
        // Nothing to hook: the module lives in the administration screen, the scheduler task and the Central App feed.
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'delivery_email', 'type' => 'text', 'label' => 'UXS_SERVICE_REQUESTS_SET_EMAIL', 'help' => 'UXS_SERVICE_REQUESTS_SET_EMAIL_HELP', 'default' => '', 'maxlength' => 250],
            ['key' => 'notify_new', 'type' => 'toggle', 'label' => 'UXS_SERVICE_REQUESTS_SET_NOTIFY', 'help' => 'UXS_SERVICE_REQUESTS_SET_NOTIFY_HELP', 'default' => true],
        ];
    }

    public function migrations(): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_service_requests` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, '
                . '`title` VARCHAR(255) NOT NULL DEFAULT \'\', `description` MEDIUMTEXT NULL, `status` VARCHAR(20) NOT NULL DEFAULT \'open\', '
                . '`requester_email` VARCHAR(255) NOT NULL DEFAULT \'\', `requester_name` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`requester_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, `page_url` VARCHAR(500) NOT NULL DEFAULT \'\', '
                . '`type` VARCHAR(20) NOT NULL DEFAULT \'bug\', `priority` VARCHAR(10) NOT NULL DEFAULT \'normal\', `environment` MEDIUMTEXT NULL, '
                . '`central_ticket_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, `central_number` VARCHAR(20) NOT NULL DEFAULT \'\', '
                . '`central_status` VARCHAR(20) NULL, `central_thread` MEDIUMTEXT NULL, '
                . '`sync_state` VARCHAR(10) NOT NULL DEFAULT \'pending\', `sync_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`sync_error` VARCHAR(500) NOT NULL DEFAULT \'\', `last_try_at` DATETIME NULL, `synced_at` DATETIME NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_status` (`status`), KEY `idx_sync` (`sync_state`, `central_ticket_id`)) ' . $opts,
                // Outbox of client replies: a failed message must not block the next one.
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_service_request_outbox` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `request_id` BIGINT UNSIGNED NOT NULL, `external_id` VARCHAR(64) NOT NULL DEFAULT \'\', '
                . '`body` MEDIUMTEXT NOT NULL, `author_name` VARCHAR(255) NOT NULL DEFAULT \'\', `author_email` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `last_error` VARCHAR(500) NOT NULL DEFAULT \'\', `last_try_at` DATETIME NULL, '
                . '`created` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `idx_request` (`request_id`)) ' . $opts,
            ],
        ];
    }

    public function tasks(): array
    {
        return ['sync' => ['label' => 'UXS_SERVICE_REQUESTS_JOB_SYNC', 'callable' => [$this, 'syncJob'], 'interval' => 'PT5M']];
    }

    /** Scheduler job: push waiting requests and replies, refresh the conversations. */
    public function syncJob(): string
    {
        $r = $this->sync()->run();

        return sprintf('pushed %d, replies %d, refreshed %d', $r['pushed'], $r['replies'], $r['pulled']);
    }

    public function routes(): array
    {
        return [
            'GET items'                => [$this, 'items'],
            'GET items/{id}'           => [$this, 'item'],
            'POST items'               => [$this, 'create'],
            'POST items/{id}/reply'    => [$this, 'reply'],
            'POST items/{id}/resync'   => [$this, 'resync'],
            'POST items/{id}/status'   => [$this, 'setStatus'],
            'POST items/{id}/delete'   => ['handler' => [$this, 'delete'], 'acl' => 'core.admin'],
            'GET state'                => [$this, 'state'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $c = $this->store()->counts();
        } catch (\Throwable $e) {
            return null;
        }
        $status = $c['failed'] > 0 ? 'bad' : ($c['waiting_client'] > 0 ? 'warn' : 'ok');

        return [
            'title'  => Text::_('UXS_SERVICE_REQUESTS_NAME'),
            'value'  => $c['open'],
            'text'   => $c['waiting_client'] > 0 ? Text::sprintf('UXS_SERVICE_REQUESTS_WIDGET_WAITING', $c['waiting_client']) : Text::sprintf('UXS_SERVICE_REQUESTS_WIDGET', $c['total']),
            'status' => $status,
        ];
    }

    /**
     * Central App feed, route "service-requests": the legacy list the Central App mirrors (status new|in_progress|completed,
     * subject, user_name ...), same shape as the WordPress NodeHealth::service_requests().
     * NOTE: CaConnector\Node::FEEDS does not list this route yet (core request), so the Central App cannot reach it today.
     */
    public function caFeed(string $route, array $query, array $body): array
    {
        if ($route !== 'service-requests') {
            return [];
        }
        $toStudio = ['new' => 'open', 'in_progress' => 'in_progress', 'completed' => 'done'];
        $toLegacy = ['open' => 'new', 'in_progress' => 'in_progress', 'done' => 'completed'];
        $filter   = isset($query['status']) && \is_string($query['status']) ? strtolower(trim($query['status'])) : '';
        if ($filter === 'rejected') {
            return []; // The local three-state column has no "rejected".
        }

        $order = ['new' => 0, 'in_progress' => 1, 'completed' => 2];
        $out   = [];
        foreach ($this->store()->rows($toStudio[$filter] ?? '') as $row) {
            $legacy = $toLegacy[(string) $row['status']] ?? 'new';
            $out[]  = [
                'id'         => (int) $row['id'],
                'subject'    => (string) $row['title'],
                'status'     => $legacy,
                'user_name'  => (string) $row['requester_name'],
                'user_email' => (string) $row['requester_email'],
                'url'        => (string) $row['page_url'],
                'budget'     => '',
                'deadline'   => '',
                'admin_note' => '',
                'created_at' => (string) $row['created'],
                'updated_at' => (string) ($row['synced_at'] ?? $row['created']),
            ];
        }
        usort($out, static fn (array $a, array $b): int => ($order[$a['status']] <=> $order[$b['status']]) ?: strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    /* ---------------------------------------------------------------- API */

    /** GET service-requests/state: is the Central App channel usable. */
    public function state(ApiRequest $r): array
    {
        return ['connected' => $this->sync()->isConfigured(), 'counts' => $this->store()->counts()];
    }

    /** GET service-requests/items?status=open|in_progress|done */
    public function items(ApiRequest $r): array
    {
        $status  = $r->string('status');
        $status  = \in_array($status, self::STATUSES, true) ? $status : '';
        $pending = $this->pending();
        $failed  = $this->store()->failedReplies(Sync::MAX_ATTEMPTS);
        $items   = [];
        foreach ($this->store()->rows($status) as $row) {
            $items[] = $this->format($row, $pending, $failed);
        }

        return ['items' => $items, 'connected' => $this->sync()->isConfigured(), 'counts' => $this->store()->counts()];
    }

    /** GET service-requests/items/{id} */
    public function item(ApiRequest $r): array
    {
        return $this->format($this->require((int) $r->param('id')), $this->pending());
    }

    /** POST service-requests/items {title, description, type, priority, page_url} */
    public function create(ApiRequest $r): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $r->string('title')) ?? '');
        if ($title === '') {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_TITLE'), 422, 'invalid_title');
        }
        $description = trim(str_replace("\0", '', $r->string('description')));
        $type        = $r->string('type');
        $priority    = $r->string('priority');
        $user        = $r->user();
        $pageUrl     = $this->cleanUrl($r->string('page_url'));
        $now         = Factory::getDate()->toSql();

        $email = trim($r->string('requester_email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) $user->email;
        }

        // The snapshot is taken now: reading it later would describe the site as it is, not as it was when the problem hit.
        $env = $this->sync()->collectEnvironment($pageUrl, $user);
        $id  = $this->store()->insert([
            'created'           => $now,
            'title'             => mb_substr($title, 0, 255),
            'description'       => mb_substr($description, 0, 20000),
            'status'            => 'open',
            'requester_email'   => mb_substr($email, 0, 255),
            'requester_name'    => mb_substr((string) ($user->name !== '' ? $user->name : $user->username), 0, 255),
            'requester_user_id' => (int) $user->id,
            'page_url'          => $pageUrl,
            'type'              => \in_array($type, self::TYPES, true) ? $type : 'bug',
            'priority'          => \in_array($priority, self::PRIORITIES, true) ? $priority : 'normal',
            'environment'       => (string) json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sync_state'        => 'pending',
        ]);

        $this->notify((array) $this->store()->row($id));

        // Best-effort immediate push; otherwise the row stays pending and the scheduler task retries it.
        $row = $this->store()->row($id);
        if ($row !== null && $this->sync()->isConfigured()) {
            $this->sync()->pushOne($row);
        }

        return $this->format($this->require($id), []);
    }

    /** POST service-requests/items/{id}/reply {body} */
    public function reply(ApiRequest $r): array
    {
        $row = $this->require((int) $r->param('id'));
        $id  = (int) $row['id'];
        if ((int) $row['central_ticket_id'] <= 0) {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_NOT_SYNCED'), 409, 'not_synced');
        }
        $body = trim(str_replace("\0", '', $r->string('body')));
        if ($body === '') {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_EMPTY_REPLY'), 422, 'empty_reply');
        }
        $user = $r->user();
        // The thread is a mirror of the Central App: the text is queued and pushed, never kept as a second local copy.
        $this->sync()->queueReply($id, mb_substr($body, 0, 20000), mb_substr((string) ($user->name !== '' ? $user->name : $user->username), 0, 255), (string) $user->email);
        $this->sync()->pullOne($id);

        return $this->format($this->require($id), $this->pending());
    }

    /** POST service-requests/items/{id}/resync: push (if the Central App does not know it yet) or pull, and flush queued replies. */
    public function resync(ApiRequest $r): array
    {
        $row  = $this->require((int) $r->param('id'));
        $id   = (int) $row['id'];
        $sync = $this->sync();
        $out  = [];
        if ($sync->isConfigured()) {
            // "Refresh" means the cause was dealt with: replies that ran out of attempts get a fresh start.
            $this->store()->outboxReset($id);
            if ((int) $row['central_ticket_id'] > 0) {
                $sync->pullOne($id);
            } else {
                $sync->resetAndPush($id);
            }
            $sync->flushOutbox(10);
            if ($sync->lastError !== null && $sync->lastError !== '') {
                $out['warning'] = mb_substr($sync->lastError, 0, 300);
            }
        }

        return $this->format($this->require($id), $this->pending()) + $out;
    }

    /** POST service-requests/items/{id}/status {status}: local status of a request that never reached the Central App. */
    public function setStatus(ApiRequest $r): array
    {
        $row    = $this->require((int) $r->param('id'));
        $status = $r->string('status');
        if (!\in_array($status, self::STATUSES, true)) {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_STATUS'), 422, 'invalid_status');
        }
        if ((int) $row['central_ticket_id'] > 0) {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_CENTRAL_OWNS'), 409, 'central_owns_status');
        }
        $this->store()->update((int) $row['id'], ['status' => $status]);

        return $this->format($this->require((int) $row['id']), $this->pending());
    }

    /** POST service-requests/items/{id}/delete: removes the local copy only (the Central App keeps the ticket). */
    public function delete(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->require($id);
        $this->store()->delete($id);

        return ['id' => $id];
    }

    /* ---------------------------------------------------------------- internals */

    /** @return array<int,int> replies still being delivered per request id */
    private function pending(): array
    {
        return $this->store()->pendingReplies(Sync::MAX_ATTEMPTS);
    }

    private function store(): Store
    {
        return new Store($this->ctx()->db());
    }

    private function sync(): Sync
    {
        return new Sync($this->ctx(), $this->store());
    }

    /** @return array<string,mixed> */
    private function require(int $id): array
    {
        $row = $this->store()->row($id);
        if ($row === null) {
            throw new ApiException(Text::_('UXS_SERVICE_REQUESTS_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $row;
    }

    /** Only http(s) URLs of at most 500 characters are kept. */
    private function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || \strlen($url) > 500 || preg_match('/[\x00-\x1f\x7f\s]/', $url) || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,int>      $pending replies waiting in the outbox per request id
     * @param array<int,array{n:int,error:string}>|null $failed replies that ran out of attempts per request id (null = look up)
     * @return array<string,mixed>
     */
    private function format(array $row, array $pending, ?array $failed = null): array
    {
        $failed ??= $this->store()->failedReplies(Sync::MAX_ATTEMPTS);
        $thread = json_decode((string) ($row['central_thread'] ?? ''), true);
        $env    = json_decode((string) ($row['environment'] ?? ''), true);
        $id     = (int) $row['id'];

        return [
            'id'                => $id,
            'created'           => (string) $row['created'],
            'title'             => (string) $row['title'],
            'description'       => (string) ($row['description'] ?? ''),
            'status'            => (string) $row['status'],
            'requester_email'   => (string) $row['requester_email'],
            'requester_name'    => (string) $row['requester_name'],
            'page_url'          => (string) $row['page_url'],
            'type'              => (string) $row['type'],
            'priority'          => (string) $row['priority'],
            'environment'       => \is_array($env) ? $env : (object) [],
            'central_ticket_id' => (int) $row['central_ticket_id'],
            'central_number'    => (string) $row['central_number'],
            'central_status'    => (string) ($row['central_status'] ?? ''),
            'thread'            => \is_array($thread) ? $thread : [],
            'pending_replies'   => $pending[$id] ?? 0,
            'failed_replies'    => $failed[$id]['n'] ?? 0,
            'failed_reply_error' => $failed[$id]['error'] ?? '',
            'sync_state'        => (string) $row['sync_state'],
            'sync_error'        => (string) $row['sync_error'],
            'synced_at'         => $row['synced_at'] ?? null,
        ];
    }

    /** Notification about a new request to the configured address (or the site's own mail address). Best effort. */
    private function notify(array $row): void
    {
        if (!$row || !(bool) $this->ctx()->setting('notify_new', true)) {
            return;
        }
        $to = trim((string) $this->ctx()->setting('delivery_email', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = (string) $this->ctx()->app()->get('mailfrom', '');
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $ok = Mail::send([
            'to'      => $to,
            'subject' => Text::sprintf('UXS_SERVICE_REQUESTS_MAIL_SUBJECT', (string) $row['title']),
            'body'    => implode("\n", [
                Text::_('UXS_SERVICE_REQUESTS_MAIL_INTRO'),
                '',
                Text::_('UXS_SERVICE_REQUESTS_MAIL_TITLE') . ': ' . $row['title'],
                Text::_('UXS_SERVICE_REQUESTS_MAIL_DESC') . ': ' . $row['description'],
                Text::_('UXS_SERVICE_REQUESTS_MAIL_FROM') . ': ' . $row['requester_name'] . ' <' . $row['requester_email'] . '>',
            ]),
            'html'    => false,
            'source'  => 'UX1 Studio: Service requests',
        ]);
        if (!$ok) {
            Log::add('service-requests: notification failed: ' . Mail::lastError(), Log::WARNING, 'uxstudio');
        }
    }
}

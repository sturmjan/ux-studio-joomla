<?php

namespace Ux1\UxStudio\Modules\GoogleReviewRequest;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Google Review Request: e-mails asking customers for a Google review. Sent by hand (one address or a pasted list),
 * or triggered by a shop / booking system through a signed public endpoint. Every attempt is logged.
 */
final class Module extends BaseModule
{
    /** Recipients per API call (the admin screen sends longer lists in several calls and shows progress). */
    private const BATCH_MAX = 10;

    /** Shortest accepted secret of the signed endpoint. */
    private const MIN_SECRET = 16;

    /** Sends e-mail from the domain of the site to arbitrary addresses, so it is an administrator-level module. */
    public function acl(): string
    {
        return 'core.admin';
    }

    public function boot(ModuleContext $ctx): void
    {
        // Nothing to hook: sending is triggered from the administration or through publicRoutes().
    }

    // ---------------------------------------------------------------- settings

    public function settingsSchema(): array
    {
        $p = 'UXS_GOOGLE_REVIEW_REQUEST_';

        return [
            ['key' => 'google_review_url', 'type' => 'text', 'section' => $p . 'SECTION_MESSAGE', 'label' => $p . 'URL', 'help' => $p . 'URL_HELP', 'default' => '', 'maxlength' => 500, 'wide' => true],
            ['key' => 'email_subject', 'type' => 'text', 'section' => $p . 'SECTION_MESSAGE', 'label' => $p . 'SUBJECT', 'help' => $p . 'PLACEHOLDERS_HELP', 'default' => $this->defaultText('DEFAULT_SUBJECT', 'How was your experience?'), 'maxlength' => 190, 'wide' => true],
            ['key' => 'email_body', 'type' => 'richtext', 'section' => $p . 'SECTION_MESSAGE', 'label' => $p . 'BODY', 'help' => $p . 'BODY_HELP', 'default' => $this->defaultText('DEFAULT_BODY', 'Hi {name},<br><br>Thank you for choosing {site}! We would really appreciate it if you could take a moment to leave us a review: <a href="{review_url}">{review_url}</a><br><br>Thank you!'), 'maxlength' => 5000],
            ['key' => 'sender_name', 'type' => 'text', 'section' => $p . 'SECTION_MESSAGE', 'label' => $p . 'SENDER_NAME', 'help' => $p . 'SENDER_NAME_HELP', 'default' => '', 'maxlength' => 120],
            ['key' => 'reply_to', 'type' => 'text', 'section' => $p . 'SECTION_MESSAGE', 'label' => $p . 'REPLY_TO', 'help' => $p . 'REPLY_TO_HELP', 'default' => '', 'maxlength' => 190],

            ['key' => 'cooldown_days', 'type' => 'number', 'section' => $p . 'SECTION_RULES', 'label' => $p . 'COOLDOWN', 'help' => $p . 'COOLDOWN_HELP', 'default' => 90, 'min' => 0, 'max' => 3650],
            ['key' => 'daily_limit', 'type' => 'number', 'section' => $p . 'SECTION_RULES', 'label' => $p . 'DAILY_LIMIT', 'help' => $p . 'DAILY_LIMIT_HELP', 'default' => 100, 'min' => 0, 'max' => 5000],
            ['key' => 'blocklist', 'type' => 'textarea', 'section' => $p . 'SECTION_RULES', 'label' => $p . 'BLOCKLIST', 'help' => $p . 'BLOCKLIST_HELP', 'default' => '', 'maxlength' => 5000, 'wide' => true],
            ['key' => 'retention_days', 'type' => 'number', 'section' => $p . 'SECTION_RULES', 'label' => $p . 'RETENTION', 'help' => $p . 'RETENTION_HELP', 'default' => 365, 'min' => 0, 'max' => 3650],

            ['key' => 'api_secret', 'type' => 'password', 'section' => $p . 'SECTION_AUTOMATION', 'label' => $p . 'API_SECRET', 'help' => $p . 'API_SECRET_HELP'],
        ];
    }

    /** Language-dependent default text; falls back to English when the language file is not loaded. */
    private function defaultText(string $key, string $fallback): string
    {
        $text = Text::_('UXS_GOOGLE_REVIEW_REQUEST_' . $key);

        return $text === 'UXS_GOOGLE_REVIEW_REQUEST_' . $key ? $fallback : $text;
    }

    // ---------------------------------------------------------------- API (administrator)

    public function routes(): array
    {
        return [
            'GET stats'   => [$this, 'stats'],
            'GET log'     => [$this, 'log'],
            'POST send'   => [$this, 'send'],
            'POST test'   => [$this, 'test'],
            'POST preview' => [$this, 'preview'],
        ];
    }

    public function publicRoutes(): array
    {
        return [
            // Signed server-to-server call from a shop / booking system: POST {email, name?, ref?} (HMAC, see docs/modules/google-review-request.md).
            'POST request' => [
                'handler'  => [$this, 'publicRequest'],
                'auth'     => 'hmac',
                // A secret shorter than MIN_SECRET characters counts as "not set" (the route stays closed).
                'secret'   => function (): string {
                    $secret = (string) $this->ctx()->setting('api_secret', '');

                    return \strlen($secret) >= self::MIN_SECRET ? $secret : '';
                },
                'limit'    => [60, 60],
                'max_body' => 4096,
            ],
            // Opt-out link from the e-mail: the first GET only shows a confirmation (mail scanners prefetch links), GET with c=1 records it.
            'GET unsubscribe' => [
                'handler' => [$this, 'unsubscribe'],
                'auth'    => 'none',
                'limit'   => [20, 60],
            ],
        ];
    }

    private function sender(): RequestSender
    {
        return new RequestSender($this->ctx());
    }

    public function stats(ApiRequest $r): array
    {
        $db    = $this->ctx()->db();
        $since = Factory::getDate('-30 days')->toSql();
        $rows  = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('status'), 'COUNT(*) AS c'])->from($db->quoteName('#__uxstudio_grr_log'))
                ->where($db->quoteName('created') . ' >= :c')->group($db->quoteName('status'))->bind(':c', $since)
        )->loadAssocList('status') ?: [];
        $total = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_log')))->loadResult();
        $today = $this->sentSince('-1 day');

        $sender = $this->sender();
        $limit  = (int) $this->ctx()->setting('daily_limit', 100);

        return [
            'sent_30d'    => (int) ($rows['sent']['c'] ?? 0),
            'failed_30d'  => (int) ($rows['failed']['c'] ?? 0),
            'skipped_30d' => (int) ($rows['skipped']['c'] ?? 0),
            'total'       => $total,
            'sent_today'  => $today,
            'daily_limit' => $limit,
            'link'        => $sender->link(),
            'api_enabled' => \strlen((string) $this->ctx()->setting('api_secret', '')) >= self::MIN_SECRET,
            'api_url'     => \Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_uxstudio&task=public.dispatch&format=json&route=google-review-request/request',
            'batch_max'   => self::BATCH_MAX,
            'mail_on'     => (bool) $this->ctx()->app()->get('mailonline', 1),
            'mailer'      => (string) $this->ctx()->app()->get('mailer', 'mail'),
        ];
    }

    public function log(ApiRequest $r): array
    {
        $db      = $this->ctx()->db();
        $status  = $r->string('status');
        $q       = mb_substr(trim($r->string('q')), 0, 100);
        $like    = '%' . addcslashes($q, '%_\\') . '%';
        $perPage = max(1, min(100, $r->int('per_page', 20)));
        $apply   = function ($query) use ($db, &$status, &$q, &$like) {
            if (\in_array($status, [RequestSender::STATUS_SENT, RequestSender::STATUS_FAILED, RequestSender::STATUS_SKIPPED], true)) {
                $query->where($db->quoteName('status') . ' = :st')->bind(':st', $status);
            }
            if ($q !== '') {
                $query->where('(' . $db->quoteName('email') . ' LIKE :q1 OR ' . $db->quoteName('name') . ' LIKE :q2 OR ' . $db->quoteName('ref') . ' LIKE :q3)')
                    ->bind(':q1', $like)->bind(':q2', $like)->bind(':q3', $like);
            }

            return $query;
        };
        $total = (int) $db->setQuery($apply($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_log'))))->loadResult();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($pages, $r->int('page', 1)));
        $items = $db->setQuery(
            $apply($db->createQuery()->select($db->quoteName(['id', 'created', 'email', 'name', 'status', 'reason', 'channel', 'ref']))->from($db->quoteName('#__uxstudio_grr_log')))->order($db->quoteName('id') . ' DESC'),
            ($page - 1) * $perPage,
            $perPage
        )->loadAssocList() ?: [];

        return ['items' => array_map(static fn (array $i): array => ['id' => (int) $i['id']] + $i, $items), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * POST send {recipients: [{email, name?, ref?}] | email, name, ref; force?: bool}
     *
     * @return array{results:array<int,array<string,string>>,sent:int,failed:int,skipped:int}
     */
    public function send(ApiRequest $r): array
    {
        $list = $r->input('recipients');
        if (!\is_array($list)) {
            $list = [['email' => $r->string('email'), 'name' => $r->string('name'), 'ref' => $r->string('ref')]];
        }
        $list = array_values(array_filter($list, 'is_array'));
        if ($list === []) {
            throw new ApiException($this->ctx()->t('UXS_GOOGLE_REVIEW_REQUEST_ERR_NO_RECIPIENTS'), 422, 'no_recipients');
        }
        if (\count($list) > self::BATCH_MAX) {
            throw new ApiException($this->ctx()->t('UXS_GOOGLE_REVIEW_REQUEST_ERR_TOO_MANY', self::BATCH_MAX), 413, 'too_many');
        }

        $channel = \count($list) > 1 ? 'bulk' : 'manual';
        $force   = $r->bool('force');
        $sender  = $this->sender();
        $out     = ['results' => [], 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $seen    = [];
        foreach ($list as $item) {
            $email = trim((string) ($item['email'] ?? ''));
            $key   = mb_strtolower($email);
            if ($key !== '' && isset($seen[$key])) {
                $res = ['email' => $key, 'status' => RequestSender::STATUS_SKIPPED, 'reason' => 'duplicate_in_list'];
            } else {
                $res = $sender->send($email, (string) ($item['name'] ?? ''), (string) ($item['ref'] ?? ''), $channel, (int) $r->user()->id, $force);
            }
            $seen[$key] = true;
            ++$out[$res['status']];
            $out['results'][] = $res;
        }

        return $out;
    }

    /** POST test - sends the request to the signed-in administrator (ignores the cool-down). */
    public function test(ApiRequest $r): array
    {
        $user = $r->user();
        if ((string) $user->email === '') {
            throw new ApiException($this->ctx()->t('UXS_GOOGLE_REVIEW_REQUEST_ERR_NO_EMAIL'), 422, 'no_email');
        }
        $res = $this->sender()->send((string) $user->email, (string) $user->name, 'test', 'test', (int) $user->id, true);

        return ['results' => [$res]] + ['sent' => (int) ($res['status'] === 'sent'), 'failed' => (int) ($res['status'] === 'failed'), 'skipped' => (int) ($res['status'] === 'skipped')];
    }

    /** POST preview {name?} - the message with the placeholders filled in (HTML is shown by the admin in a sandbox). */
    public function preview(ApiRequest $r): array
    {
        $sender = $this->sender();

        return $sender->compose($r->string('name')) + ['link' => $sender->link()];
    }

    /** Public (HMAC) route: a shop / booking system asks for one review request e-mail. */
    public function publicRequest(ApiRequest $r): array
    {
        $email = $r->string('email');
        if ($email === '') {
            throw new ApiException('email is required', 422, 'email_required');
        }
        // The signature stays valid for the whole tolerance window: remember it so the same request cannot be replayed.
        $sig = strtolower((string) $this->ctx()->app()->getInput()->server->get('HTTP_X_UXS_SIGNATURE', '', 'string'));
        if ($sig !== '' && (new RateLimiter($this->ctx()->db()))->hit('grr-sig:' . $sig, 1, 900) > 0) {
            throw new ApiException('This signed request was already processed.', 409, 'replayed');
        }
        $res = $this->sender()->send($email, $r->string('name'), $r->string('ref'), 'api', null, false);
        if ($res['status'] === RequestSender::STATUS_FAILED) {
            // Do not leak the mail transport error to the caller.
            throw new ApiException('The e-mail could not be sent.', 502, 'send_failed');
        }

        return ['status' => $res['status'], 'reason' => $res['status'] === RequestSender::STATUS_SKIPPED ? $res['reason'] : ''];
    }

    /** Public GET: the opt-out page. Answers with a small HTML page (not JSON), because a person opens it from an e-mail. */
    public function unsubscribe(ApiRequest $r): array
    {
        $esc   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title = Text::_('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_TITLE');
        $email = mb_strtolower(trim($r->string('e')));
        if ($email === '' || !hash_equals(RequestSender::unsubscribeToken($email), $r->string('t'))) {
            $this->page($title, '<p>' . $esc(Text::_('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_INVALID')) . '</p>', 400);
        }
        if ($r->string('c') === '1') {
            $this->sender()->optOut($email);
            $this->page($title, '<p>' . $esc(Text::sprintf('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_DONE', $email)) . '</p>', 200);
        }
        $this->page(
            $title,
            '<p>' . $esc(Text::sprintf('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_ASK', $email)) . '</p><p><a class="b" href="' . $esc(RequestSender::unsubscribeUrl($email) . '&c=1') . '">'
            . $esc(Text::_('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_BUTTON')) . '</a></p>',
            200
        );
    }

    /** Sends a complete HTML page and ends the request. */
    private function page(string $title, string $body, int $status): never
    {
        $app  = $this->ctx()->app();
        $site = htmlspecialchars((string) $app->get('sitename', ''), ENT_QUOTES, 'UTF-8');
        $t    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $t . ' - ' . $site . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;line-height:1.5}.b{display:inline-block;padding:.6rem 1.2rem;background:#4f46e5;color:#fff;border-radius:6px;text-decoration:none}</style>'
            . '</head><body><h1>' . $t . '</h1>' . $body . '</body></html>';
        $app->close();
        exit;
    }

    private function sentSince(string $modifier): int
    {
        $db    = $this->ctx()->db();
        $since = Factory::getDate($modifier)->toSql();
        $sent  = RequestSender::STATUS_SENT;

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_log'))->where($db->quoteName('status') . ' = :s')->where($db->quoteName('created') . ' >= :c')->bind(':s', $sent)->bind(':c', $since)
        )->loadResult();
    }

    public function dashboardWidget(): ?array
    {
        try {
            $sent = $this->sentSince('-30 days');
        } catch (\Throwable $e) {
            return null;
        }
        $linkOk = $this->sender()->link() !== '';

        return [
            'title'  => $this->ctx()->t('UXS_GOOGLE_REVIEW_REQUEST_NAME'),
            'value'  => $sent,
            'text'   => $this->ctx()->t($linkOk ? 'UXS_GOOGLE_REVIEW_REQUEST_WIDGET_TEXT' : 'UXS_GOOGLE_REVIEW_REQUEST_WIDGET_NO_LINK'),
            'status' => $linkOk ? 'ok' : 'warn',
        ];
    }

    // ---------------------------------------------------------------- migrations / retention

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_grr_log` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`created` DATETIME NOT NULL, '
                . '`email` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`name` VARCHAR(120) NOT NULL DEFAULT \'\', '
                . '`status` VARCHAR(12) NOT NULL DEFAULT \'\', '
                . '`reason` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`channel` VARCHAR(12) NOT NULL DEFAULT \'manual\', '
                . '`ref` VARCHAR(100) NOT NULL DEFAULT \'\', '
                . '`user_id` INT UNSIGNED NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_email_status` (`email`, `status`, `created`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
            // Addresses that used the opt-out link. Kept as the record of the refusal (not purged by retention).
            2 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_grr_optout` ('
                . '`email` VARCHAR(190) NOT NULL, `created` DATETIME NOT NULL, PRIMARY KEY (`email`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_grr_log', 'column' => 'created', 'days' => fn (): int => (int) $this->ctx()->setting('retention_days', 365)]];
    }
}

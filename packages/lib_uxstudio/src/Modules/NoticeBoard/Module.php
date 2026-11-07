<?php

namespace Ux1\UxStudio\Modules\NoticeBoard;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

/**
 * Notice Board (public "úřední deska"): notices with body, publish / expiry dates and automatic archiving, attachments from
 * the Joomla media folder, categories, per-category e-mail subscriptions (double opt-in) with notification on publish, an RSS
 * feed (?uxs_nb=feed), national open data (?uxs_nb=open-data) and a front-end board for articles: {uxs-notice-board}.
 */
final class Module extends BaseModule
{
    /** Minimum gap between two confirmation e-mails to one address (hours): the public form must not mail-bomb anyone. */
    private const CONFIRM_RESEND_HOURS = 6;

    /** Messages one admin request may send; bigger announcements are left to the hourly scheduler job. */
    private const ADMIN_MAIL_BATCH = 50;

    private ?Store $store = null;

    private ?Frontend $frontend = null;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onAfterRoute', [$this, 'handleRequest']);
            $ctx->on('onContentPrepare', [$this, 'contentPrepare']);
        }
    }

    public function store(): Store
    {
        if ($this->store === null) {
            $ctx         = $this->ctx();
            $this->store = new Store($ctx->db(), $this->timezone());
            $this->store->setRootProvider(fn (): string => $this->siteRoot());
        }

        return $this->store;
    }

    private function frontend(): Frontend
    {
        return $this->frontend ??= new Frontend($this->store(), $this->ctx());
    }

    /**
     * Canonical site root with trailing slash. In a web request it is Uri::root() (and remembered); under CLI (scheduler:run, cron)
     * Joomla invents a "joomla.invalid" host, so the remembered value (or live_site) is used there instead.
     */
    public function siteRoot(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $app = $this->ctx()->app();
        if ($app->isClient('site') || $app->isClient('administrator')) {
            $root = Uri::root();
            if (!str_contains($root, 'joomla.invalid')) {
                try {
                    if ($this->store()->getMeta('site_root') !== $root) {
                        $this->store()->setMeta('site_root', $root);
                    }
                } catch (\Throwable $e) {
                    Log::add("Notice board: remembering the site root failed: " . $e->getMessage(), Log::WARNING, "uxstudio");
                }
            }

            return $cached = $root;
        }
        $live = trim((string) $app->get('live_site', ''));
        if ($live !== '') {
            return $cached = rtrim($live, '/') . '/';
        }
        try {
            $stored = $this->store()->getMeta('site_root');
        } catch (\Throwable $e) {
            $stored = '';
        }

        return $cached = ($stored !== '' ? $stored : Uri::root());
    }

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $this->ctx()->app()->get('offset', 'UTC'));
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /* ---------------------------------------------------------------- settings, schema, jobs */

    public function settingsSchema(): array
    {
        $p = 'UXS_NOTICE_BOARD_';

        return [
            ['key' => 'notify_on_publish', 'type' => 'toggle', 'label' => $p . 'NOTIFY', 'help' => $p . 'NOTIFY_HELP', 'default' => true],
            ['key' => 'from_email', 'type' => 'text', 'label' => $p . 'FROM_EMAIL', 'help' => $p . 'FROM_EMAIL_HELP', 'default' => '', 'maxlength' => 190, 'show_if' => ['key' => 'notify_on_publish', 'truthy' => true]],
            ['key' => 'per_page', 'type' => 'number', 'label' => $p . 'PER_PAGE', 'default' => 20, 'min' => 1, 'max' => 100],
            ['key' => 'retention_days', 'type' => 'number', 'label' => $p . 'RETENTION', 'help' => $p . 'RETENTION_HELP', 'default' => 0, 'min' => 0, 'max' => 3650],
            ['key' => 'board_url', 'type' => 'text', 'label' => $p . 'BOARD_URL', 'help' => $p . 'BOARD_URL_HELP', 'default' => '', 'maxlength' => 300],
        ];
    }

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_notice_board` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NULL, '
                . '`title` VARCHAR(255) NOT NULL DEFAULT \'\', `body` MEDIUMTEXT NULL, `category` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`reference` VARCHAR(190) NOT NULL DEFAULT \'\', `attachments` TEXT NULL, `publish_date` DATE NULL, `expiry_date` DATE NULL, '
                . '`is_archived` TINYINT(1) NOT NULL DEFAULT 0, `notified` TINYINT(1) NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`id`), KEY `idx_category` (`category`), KEY `idx_archived` (`is_archived`, `expiry_date`), KEY `idx_publish` (`publish_date`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_notice_board_categories` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(190) NOT NULL DEFAULT \'\', `slug` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`sort_order` INT NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uniq_slug` (`slug`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_notice_board_subscriptions` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created_at` DATETIME NOT NULL, `email` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`confirmed` TINYINT(1) NOT NULL DEFAULT 0, `confirm_token` VARCHAR(64) NOT NULL DEFAULT \'\', `unsubscribe_token` VARCHAR(64) NOT NULL DEFAULT \'\', '
                . '`categories` TEXT NULL, `pending_categories` TEXT NULL, `confirm_sent_at` DATETIME NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_email` (`email`), KEY `idx_confirm` (`confirm_token`), KEY `idx_unsub` (`unsubscribe_token`))' . $tail,
            ],
            2 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_notice_board_meta` (`k` VARCHAR(64) NOT NULL, `v` TEXT NULL, PRIMARY KEY (`k`))' . $tail,
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'housekeeping' => ['label' => 'UXS_NOTICE_BOARD_JOB_HOUSEKEEPING', 'callable' => [$this, 'housekeeping'], 'interval' => 'PT1H'],
        ];
    }

    /** Scheduler job: archive expired notices, announce newly published ones by e-mail, apply the retention. */
    public function housekeeping(): string
    {
        $store    = $this->store();
        $archived = $store->archiveExpired();
        $sent     = $this->notifyDue();
        $purged   = $store->purgeArchived((int) $this->ctx()->setting('retention_days', 0));

        return sprintf('archived %d, mailed %d, deleted %d', $archived, $sent, $purged);
    }

    public function dashboardWidget(): ?array
    {
        try {
            $counts = $this->store()->countPublic();
            $subs   = $this->store()->countSubscribers();
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => Text::_('UXS_NOTICE_BOARD_WIDGET_TITLE'),
            'value'  => $counts['active'],
            'text'   => Text::sprintf('UXS_NOTICE_BOARD_WIDGET_TEXT', $counts['archived'], $subs['confirmed']),
            'status' => 'ok',
        ];
    }

    /* ---------------------------------------------------------------- front end */

    /** {uxs-notice-board category="slug" limit="20" subscribe="1" filters="1"} (also {uxstudio_notice_board ...}) in content. */
    public function contentPrepare(Event $event): void
    {
        $item = $event->hasArgument('subject') ? $event->getArgument('subject') : $event->getArgument(1);
        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !preg_match('/\{(uxs-notice-board|uxstudio_notice_board)/', $item->text)) {
            return;
        }
        $context = (string) ($event->hasArgument('context') ? $event->getArgument('context') : $event->getArgument(0));
        if ($context === 'com_finder.indexer') {
            return;
        }
        $item->text = (string) preg_replace_callback(
            '/\{(?:uxs-notice-board|uxstudio_notice_board)([^}]*)\}/',
            function (array $m): string {
                $atts = ['category' => '', 'limit' => '', 'subscribe' => '1', 'filters' => '1'];
                if (preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $m[1], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $atts[strtolower($p[1])] = $p[4] ?? ($p[3] ?? $p[2]);
                    }
                }
                try {
                    return $this->frontend()->render($atts);
                } catch (\Throwable $e) {
                    Log::add('Notice board render failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');

                    return '';
                }
            },
            $item->text
        );
    }

    /** onAfterRoute: ?uxs_nb=feed|open-data|confirm|unsubscribe (these need a plain response or a redirect, not the JSON envelope). */
    public function handleRequest(Event $event): void
    {
        $app    = $this->ctx()->app();
        $input  = $app->getInput();
        $action = $input->getCmd('uxs_nb', '');
        if ($action === '') {
            return;
        }
        try {
            switch ($action) {
                case 'feed':
                    $xml = $this->frontend()->rss((string) $input->getString('cat', ''), max(1, min(100, (int) $this->ctx()->setting('per_page', 20))));
                    $this->respond('application/rss+xml; charset=utf-8', $xml);
                    break;
                case 'open-data':
                    $this->respond('application/ld+json; charset=utf-8', (string) json_encode($this->frontend()->openData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                    break;
                case 'subscribe':
                    $this->handleFormSubscribe();
                    break;
                case 'confirm':
                case 'unsubscribe':
                    $token = (string) $input->getString('token', '');
                    $ok    = $action === 'confirm' ? $this->store()->confirm($token) : $this->store()->unsubscribe($token);
                    $key   = 'UXS_NOTICE_BOARD_' . ($action === 'confirm' ? 'CONFIRMED' : 'UNSUBSCRIBED') . ($ok ? '' : '_INVALID');
                    $app->enqueueMessage(Text::_($key), $ok ? 'message' : 'warning');
                    $app->redirect($this->frontend()->boardUrl());
                    break;
            }
        } catch (\Throwable $e) {
            Log::add('Notice board request failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');
        }
    }

    /** No-JavaScript subscription: classic POST of the board form (Joomla form token + rate limit), then redirect with a message. */
    private function handleFormSubscribe(): void
    {
        $app   = $this->ctx()->app();
        $input = $app->getInput();
        $back  = $this->frontend()->boardUrl();
        if (strtoupper((string) $input->getMethod()) !== 'POST') {
            $app->redirect($back);
        }
        if (!Session::checkToken('post')) {
            $app->enqueueMessage(Text::_('JINVALID_TOKEN_NOTICE'), 'warning');
            $app->redirect($back);
        }
        if ((new RateLimiter($this->ctx()->db()))->hitClient('notice-board:subscribe-form', 5, 300) > 0) {
            $app->enqueueMessage(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_ERROR'), 'warning');
            $app->redirect($back);
        }
        $this->trySubscribe((string) $input->post->getString('email', ''), []);
        $app->enqueueMessage(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_OK'), 'message');
        $app->redirect($back);
    }

    private function respond(string $contentType, string $body): void
    {
        $app = $this->ctx()->app();
        $app->setHeader('Content-Type', $contentType, true);
        $app->setHeader('Cache-Control', 'public, max-age=300', true);
        $app->setHeader('X-Robots-Tag', 'noindex', true);
        $app->sendHeaders();
        echo $body;
        $app->close();
    }

    /* ---------------------------------------------------------------- API (administrator) */

    public function routes(): array
    {
        return [
            'GET notices'                  => [$this, 'apiNotices'],
            'GET notices/{id}'             => [$this, 'apiNotice'],
            'POST notices'                 => [$this, 'apiCreateNotice'],
            'POST notices/{id}'            => [$this, 'apiUpdateNotice'],
            'POST notices/{id}/delete'     => ['handler' => [$this, 'apiDeleteNotice'], 'acl' => 'core.admin'],
            'GET categories'               => [$this, 'apiCategories'],
            'POST categories'              => [$this, 'apiCreateCategory'],
            'POST categories/{id}'         => [$this, 'apiUpdateCategory'],
            'POST categories/{id}/delete'  => ['handler' => [$this, 'apiDeleteCategory'], 'acl' => 'core.admin'],
            'GET subscribers'              => ['handler' => [$this, 'apiSubscribers'], 'acl' => 'core.admin'],
            'POST subscribers/{id}/delete' => ['handler' => [$this, 'apiDeleteSubscriber'], 'acl' => 'core.admin'],
        ];
    }

    /** Public: double opt-in subscription. Always answers the same, so addresses cannot be enumerated. */
    public function publicRoutes(): array
    {
        return [
            'POST subscribe' => ['handler' => [$this, 'apiSubscribe'], 'auth' => 'none', 'limit' => [5, 300], 'max_body' => 4096],
            'GET open-data'  => ['handler' => [$this, 'apiOpenData'], 'auth' => 'none', 'limit' => [30, 60]],
        ];
    }

    public function apiNotices(ApiRequest $r): array
    {
        $this->siteRoot();
        $this->store()->archiveExpired();
        $store = $this->store();

        return ['items' => $store->listNotices($r->query('include_archived', '1') !== '0'), 'counts' => $store->countPublic()];
    }

    public function apiNotice(ApiRequest $r): array
    {
        $n = $this->store()->getNotice((int) $r->param('id'));
        if ($n === null) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $n;
    }

    public function apiCreateNotice(ApiRequest $r): array
    {
        $notice = $this->store()->createNotice($r->body());
        $this->notifyDue(self::ADMIN_MAIL_BATCH);

        return $this->store()->getNotice((int) $notice['id']) ?? $notice;
    }

    public function apiUpdateNotice(ApiRequest $r): array
    {
        $notice = $this->store()->updateNotice((int) $r->param('id'), $r->body());
        $this->notifyDue(self::ADMIN_MAIL_BATCH);

        return $this->store()->getNotice((int) $notice['id']) ?? $notice;
    }

    public function apiDeleteNotice(ApiRequest $r): array
    {
        $this->store()->deleteNotice((int) $r->param('id'));

        return ['deleted' => true];
    }

    public function apiCategories(ApiRequest $r): array
    {
        return ['items' => $this->store()->listCategories()];
    }

    public function apiCreateCategory(ApiRequest $r): array
    {
        return $this->store()->createCategory($r->string('name'), $r->int('sort_order'));
    }

    public function apiUpdateCategory(ApiRequest $r): array
    {
        return $this->store()->updateCategory((int) $r->param('id'), $r->body());
    }

    public function apiDeleteCategory(ApiRequest $r): array
    {
        $this->store()->deleteCategory((int) $r->param('id'));

        return ['deleted' => true];
    }

    public function apiSubscribers(ApiRequest $r): array
    {
        return ['items' => $this->store()->listSubscriptions(), 'counts' => $this->store()->countSubscribers()];
    }

    public function apiDeleteSubscriber(ApiRequest $r): array
    {
        $this->store()->deleteSubscription((int) $r->param('id'));

        return ['deleted' => true];
    }

    public function apiOpenData(ApiRequest $r): array
    {
        return $this->frontend()->openData();
    }

    public function apiSubscribe(ApiRequest $r): array
    {
        $cats = $r->input('categories', []);
        $this->trySubscribe($r->string('email'), \is_array($cats) ? $cats : []);

        return ['ok' => true];
    }

    private function trySubscribe(string $email, array $cats): void
    {
        $email = trim($email);
        if ($email !== '' && mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $this->subscribe($email, $cats);
            } catch (\Throwable $e) {
                Log::add('Notice board subscribe failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');
            }
        }
    }

    /* ---------------------------------------------------------------- subscriptions and e-mail */

    /**
     * Unconfirmed address: category set updated, confirmation re-sent. Confirmed address: the new category set is only
     * parked and applied after the owner clicks a fresh link (anyone can submit the form for any address).
     */
    public function subscribe(string $email, array $categories): void
    {
        $store = $this->store();
        $cats  = $store->cleanCategorySlugs($categories);
        $row   = $store->findSubscription($email);

        if ($row !== null) {
            $id        = (int) $row['id'];
            $confirmed = (bool) (int) $row['confirmed'];
            $token     = (string) $row['confirm_token'];
            $last      = (string) ($row['confirm_sent_at'] ?? '');
            $canMail   = $last === '' || (strtotime($last . ' UTC') ?: 0) < time() - self::CONFIRM_RESEND_HOURS * 3600;

            if ($confirmed && !$canMail) {
                return;
            }
            if ($confirmed) {
                $token = Store::token();
                $store->patchSubscription($id, ['pending_categories' => $cats, 'confirm_token' => $token]);
            } else {
                $store->patchSubscription($id, ['categories' => $cats]);
            }
            if ($canMail && $this->sendConfirmMail($email, $token, $confirmed)) {
                $store->patchSubscription($id, ['confirm_sent' => true]);
            }

            return;
        }

        $confirm = Store::token();
        $id      = $store->insertSubscription($email, $cats, $confirm, Store::token());
        if ($this->sendConfirmMail($email, $confirm, false)) {
            $store->patchSubscription($id, ['confirm_sent' => true]);
        }
    }

    private function sendMail(string $to, string $subject, string $html): bool
    {
        try {
            $mailer = \Ux1\UxStudio\Core\Mail::create();
            $from   = trim((string) $this->ctx()->setting('from_email', ''));
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $mailer->setSender([$from, (string) $this->ctx()->app()->get('sitename', '')]);
            }
            $mailer->addRecipient($to);
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->setBody($html);

            return $mailer->send() === true;
        } catch (\Throwable $e) {
            Log::add('Notice board mail failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');

            return false;
        }
    }

    private function sendConfirmMail(string $email, string $token, bool $change): bool
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $site = (string) $this->ctx()->app()->get('sitename', '');
        $url  = $this->siteRoot() . 'index.php?uxs_nb=confirm&token=' . rawurlencode($token);
        $body = '<p>' . $e(Text::_($change ? 'UXS_NOTICE_BOARD_MAIL_CONFIRM_CHANGE' : 'UXS_NOTICE_BOARD_MAIL_CONFIRM')) . '</p>'
            . '<p><a href="' . $e($url) . '">' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_CONFIRM_LINK')) . '</a></p>'
            . '<p style="color:#787c82;font-size:13px;">' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_IGNORE')) . '</p>';
        return $this->sendMail($email, Text::sprintf('UXS_NOTICE_BOARD_MAIL_CONFIRM_SUBJECT', $site), $body);
    }

    /**
     * E-mails every published, not yet announced notice to the matching confirmed subscribers (no category filter = all).
     * A notice is marked as announced even when notifications are off, so switching them on later does not mail old notices.
     *
     * @param int|null $maxMails budget of messages for this call (admin requests); a notice that does not fit stays for the scheduler job
     * @return int number of messages sent
     */
    public function notifyDue(?int $maxMails = null): int
    {
        $store = $this->store();
        $due   = $store->dueForNotification();
        if ($due === []) {
            return 0;
        }
        $enabled     = (bool) $this->ctx()->setting('notify_on_publish', true);
        $subscribers = $enabled ? $store->confirmedSubscribers() : [];
        $sent        = 0;
        foreach ($due as $notice) {
            $recipients = array_values(array_filter($subscribers, static fn (array $s): bool => $s['categories'] === [] || \in_array($notice['category'], $s['categories'], true)));
            if ($maxMails !== null && $sent + \count($recipients) > $maxMails) {
                continue;
            }
            $store->markNotified((int) $notice['id']);
            foreach ($recipients as $s) {
                if ($this->sendMail($s['email'], Text::sprintf('UXS_NOTICE_BOARD_MAIL_NEW_SUBJECT', (string) $this->ctx()->app()->get('sitename', ''), $notice['title']), $this->notificationBody($notice, $s['unsubscribe_token']))) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function notificationBody(array $notice, string $unsubscribeToken): string
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $body = '<h2>' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_NEW_TITLE')) . '</h2><p><strong>' . $e($notice['title']) . '</strong></p>';
        if ($notice['reference'] !== '') {
            $body .= '<p>' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_REFERENCE')) . ' ' . $e($notice['reference']) . '</p>';
        }
        if ($notice['publish_date'] !== '') {
            $body .= '<p>' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_PUBLISHED')) . ' ' . $e(HTMLHelper::_('date', $notice['publish_date'] . ' 12:00:00', Text::_('DATE_FORMAT_LC3'), false)) . '</p>';
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($notice['body'])) ?? '');
        if ($text !== '') {
            $body .= '<p>' . $e(mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text) . '</p>';
        }
        $board = $this->frontend()->noticeUrl($notice);
        $body .= '<p><a href="' . $e($board) . '">' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_OPEN')) . '</a></p>'
            . '<hr><p style="color:#a7aaad;font-size:12px;"><a href="' . $e($this->siteRoot() . 'index.php?uxs_nb=unsubscribe&token=' . rawurlencode($unsubscribeToken)) . '">' . $e(Text::_('UXS_NOTICE_BOARD_MAIL_UNSUBSCRIBE')) . '</a></p>';

        return $body;
    }
}

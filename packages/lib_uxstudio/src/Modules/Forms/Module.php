<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Language\Text;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Hooks;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Form builder: forms with fields, server-side validation, spam protection, e-mail notifications, file uploads and an
 * archive of submissions. Embedding: {uxsform N} shortcode in content and the mod_uxstudio_form Joomla module.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onContentPrepare', [$this, 'shortcode']);
            // Turnstile on forms needs challenges.cloudflare.com: an enforced CSP (security-optimization) must allow it.
            Hooks::add('csp.sources', function (array $sources) use ($ctx): array {
                if ((string) $ctx->setting('turnstile_site_key', '') !== '' && (string) $ctx->setting('turnstile_secret', '') !== '') {
                    $sources['script-src'][] = 'https://challenges.cloudflare.com';
                    $sources['frame-src'][]  = 'https://challenges.cloudflare.com';
                }

                return $sources;
            });
        }
    }

    /** Replaces {uxsform 3} (also {uxsform id=3}) in articles, custom modules and any text passed through onContentPrepare. */
    public function shortcode(Event $event): void
    {
        $context = (string) ($event->getArgument('context') ?? $event->getArgument(0) ?? '');
        if ($context === 'com_finder.indexer') {
            return;
        }
        $item = $event->getArgument('subject') ?? $event->getArgument(1);
        if (!\is_object($item)) {
            return;
        }
        foreach (['text', 'introtext', 'fulltext'] as $prop) {
            if (!isset($item->$prop) || !\is_string($item->$prop) || stripos($item->$prop, '{uxsform') === false) {
                continue;
            }
            $item->$prop = (string) preg_replace_callback(
                '#(<p>\s*)?\{uxsform\s+(?:id\s*=\s*)?(\d+)\s*\}(\s*</p>)?#i',
                fn (array $m): string => Renderer::render($this->ctx(), (int) $m[2]),
                $item->$prop
            );
        }
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'default_to', 'type' => 'text', 'label' => 'UXS_FORMS_SET_DEFAULT_TO', 'help' => 'UXS_FORMS_SET_DEFAULT_TO_HELP', 'default' => '', 'maxlength' => 250, 'section' => 'UXS_FORMS_SEC_GENERAL'],
            ['key' => 'load_css', 'type' => 'toggle', 'label' => 'UXS_FORMS_SET_LOAD_CSS', 'help' => 'UXS_FORMS_SET_LOAD_CSS_HELP', 'default' => true, 'section' => 'UXS_FORMS_SEC_GENERAL'],
            ['key' => 'accent_color', 'type' => 'color', 'label' => 'UXS_FORMS_SET_ACCENT', 'default' => '#2563eb', 'section' => 'UXS_FORMS_SEC_GENERAL'],
            ['key' => 'max_upload_mb', 'type' => 'number', 'label' => 'UXS_FORMS_SET_MAX_UPLOAD', 'help' => 'UXS_FORMS_SET_MAX_UPLOAD_HELP', 'default' => 5, 'min' => 1, 'max' => 5, 'section' => 'UXS_FORMS_SEC_GENERAL'],
            ['key' => 'retention_days', 'type' => 'number', 'label' => 'UXS_FORMS_SET_RETENTION', 'help' => 'UXS_FORMS_SET_RETENTION_HELP', 'default' => 0, 'min' => 0, 'max' => 3650, 'section' => 'UXS_FORMS_SEC_GENERAL'],
            ['key' => 'rate_limit', 'type' => 'number', 'label' => 'UXS_FORMS_SET_RATE', 'help' => 'UXS_FORMS_SET_RATE_HELP', 'default' => 10, 'min' => 1, 'max' => 1000, 'section' => 'UXS_FORMS_SEC_SPAM'],
            ['key' => 'turnstile_site_key', 'type' => 'text', 'label' => 'UXS_FORMS_SET_TS_SITE', 'help' => 'UXS_FORMS_SET_TS_HELP', 'default' => '', 'maxlength' => 100, 'section' => 'UXS_FORMS_SEC_SPAM'],
            ['key' => 'turnstile_secret', 'type' => 'password', 'label' => 'UXS_FORMS_SET_TS_SECRET', 'section' => 'UXS_FORMS_SEC_SPAM'],
        ];
    }

    public function routes(): array
    {
        return [
            'GET list'                          => [$this, 'listForms'],
            'GET new'                           => [$this, 'newForm'],
            'GET form/{id}'                     => [$this, 'getForm'],
            'POST form'                         => [$this, 'createForm'],
            'POST form/{id}'                    => [$this, 'updateForm'],
            'POST form/{id}/delete'             => ['handler' => [$this, 'deleteForm'], 'acl' => 'core.admin'],
            'POST form/{id}/duplicate'          => [$this, 'duplicateForm'],
            'GET form/{id}/export'              => ['handler' => [$this, 'exportCsv'], 'acl' => 'core.admin'],
            'GET submissions'                   => [$this, 'listSubmissions'],
            'GET submission/{id}'               => [$this, 'getSubmission'],
            'POST submissions/read'             => [$this, 'markRead'],
            'POST submissions/delete'           => ['handler' => [$this, 'deleteSubmissions'], 'acl' => 'core.admin'],
            'GET submission/{id}/file/{key}/{n}' => [$this, 'downloadFile'],
        ];
    }

    /** Public submit endpoint: browsers only (Origin must be this site), rate limited, body capped (files travel as base64). */
    public function publicRoutes(): array
    {
        return [
            'POST submit' => ['handler' => [$this, 'submit'], 'auth' => 'none', 'limit' => [30, 60], 'max_body' => 8388608, 'max_files' => 15, 'max_file_size' => 5242880],
        ];
    }

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_forms_forms` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(190) NOT NULL DEFAULT \'\', `status` VARCHAR(12) NOT NULL DEFAULT \'published\', '
                . '`fields` MEDIUMTEXT NOT NULL, `settings` MEDIUMTEXT NOT NULL, `created` DATETIME NOT NULL, `modified` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_status` (`status`))' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_forms_submissions` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `form_id` INT UNSIGNED NOT NULL, `created` DATETIME NOT NULL, `is_read` TINYINT(1) NOT NULL DEFAULT 0, '
                . '`ip` VARCHAR(64) NOT NULL DEFAULT \'\', `user_agent` VARCHAR(255) NOT NULL DEFAULT \'\', `page_url` VARCHAR(500) NOT NULL DEFAULT \'\', '
                . '`data` MEDIUMTEXT NOT NULL, `files` MEDIUMTEXT NULL, `mail_status` VARCHAR(16) NOT NULL DEFAULT \'none\', '
                . 'PRIMARY KEY (`id`), KEY `idx_form_created` (`form_id`, `created`), KEY `idx_created` (`created`), KEY `idx_read` (`is_read`))' . $tail,
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'cleanup' => ['label' => 'UXS_FORMS_JOB_CLEANUP', 'callable' => [$this, 'cleanup'], 'interval' => 'P1D'],
        ];
    }

    /** Scheduler job forms.cleanup: removes submissions (and their files) older than the retention setting. */
    public function cleanup(): string
    {
        $days = (int) $this->ctx()->setting('retention_days', 0);
        if ($days <= 0) {
            return 'Retention disabled.';
        }

        return 'Removed ' . (new Submissions($this->ctx()->db()))->purgeOlderThan($days) . ' submissions.';
    }

    public function onEnable(): void
    {
        try {
            FileStore::ensureBase();
        } catch (\Throwable $e) {
            // The upload folder is created again on the first upload; the error surfaces there.
        }
    }

    public function dashboardWidget(): ?array
    {
        $unread = (new Submissions($this->ctx()->db()))->unreadCount();

        return [
            'title'  => Text::_('UXS_FORMS_NAME'),
            'value'  => $unread,
            'text'   => Text::_('UXS_FORMS_WIDGET_UNREAD'),
            'status' => $unread > 0 ? 'warn' : 'ok',
        ];
    }

    /* ---- public ---- */

    public function submit(ApiRequest $r): array
    {
        return (new Submitter($this->ctx()))->handle($r);
    }

    /* ---- administrator API ---- */

    public function listForms(ApiRequest $r): array
    {
        $ctx = $this->ctx();

        return [
            'forms'     => (new Forms($ctx->db()))->all(),
            'turnstile' => (string) $ctx->setting('turnstile_site_key', '') !== '' && (string) $ctx->setting('turnstile_secret', '') !== '',
        ];
    }

    public function newForm(ApiRequest $r): array
    {
        return ['form' => [
            'id'        => 0,
            'title'     => '',
            'status'    => 'published',
            'fields'    => Forms::starter(),
            'settings'  => Forms::defaultSettings(),
            'shortcode' => '',
        ]];
    }

    public function getForm(ApiRequest $r): array
    {
        return ['form' => $this->requireForm((int) $r->param('id'))];
    }

    public function createForm(ApiRequest $r): array
    {
        return $this->persist(0, $r);
    }

    public function updateForm(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->requireForm($id);

        return $this->persist($id, $r);
    }

    public function deleteForm(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->requireForm($id);
        $db = $this->ctx()->db();
        (new Submissions($db))->deleteForForm($id);
        (new Forms($db))->delete($id);

        return ['deleted' => $id];
    }

    public function duplicateForm(ApiRequest $r): array
    {
        $form = $this->requireForm((int) $r->param('id'));
        $new  = (new Forms($this->ctx()->db()))->save(0, [
            'title'    => Text::sprintf('UXS_FORMS_COPY_OF', $form['title']),
            'status'   => 'draft',
            'fields'   => $form['fields'],
            'settings' => $form['settings'],
        ]);

        return ['form' => $this->requireForm($new)];
    }

    public function exportCsv(ApiRequest $r): array
    {
        $form = $this->requireForm((int) $r->param('id'));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $form['title']))), '-');

        return [
            'filename' => 'form-' . ($slug !== '' ? $slug : $form['id']) . '-' . gmdate('Ymd') . '.csv',
            'csv'      => (new Submissions($this->ctx()->db()))->csv($form, $r->string('q')),
        ];
    }

    public function listSubmissions(ApiRequest $r): array
    {
        $status = $r->string('status') === 'unread' ? 'unread' : 'all';
        $res    = (new Submissions($this->ctx()->db()))->search(max(0, $r->int('form_id')), mb_substr($r->string('q'), 0, 100), $status, max(1, $r->int('page', 1)), 20);
        // The list needs labels only for a summary column; the full data comes with the detail call.
        foreach ($res['items'] as &$item) {
            $item['files'] = array_map('count', $item['files']);
            $item['data']  = array_map(static fn ($v) => \is_array($v) ? implode(', ', $v) : (string) $v, \array_slice($item['data'], 0, 4, true));
        }
        unset($item);

        return $res;
    }

    public function getSubmission(ApiRequest $r): array
    {
        $db   = $this->ctx()->db();
        $repo = new Submissions($db);
        $item = $repo->get((int) $r->param('id'));
        if ($item === null) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $form = (new Forms($db))->get($item['form_id']);
        if (!$item['is_read']) {
            $repo->markRead([$item['id']], true);
            $item['is_read'] = true;
        }

        return ['submission' => $item, 'fields' => $form ? $form['fields'] : []];
    }

    public function markRead(ApiRequest $r): array
    {
        $ids = array_map('intval', (array) $r->input('ids', []));
        (new Submissions($this->ctx()->db()))->markRead($ids, $r->bool('read', true));

        return ['updated' => \count($ids)];
    }

    public function deleteSubmissions(ApiRequest $r): array
    {
        $ids = \array_slice(array_map('intval', (array) $r->input('ids', [])), 0, 500);

        return ['deleted' => (new Submissions($this->ctx()->db()))->delete($ids)];
    }

    /** Streams a stored upload to the SPA as base64 (files never have a public URL). */
    public function downloadFile(ApiRequest $r): array
    {
        $item = (new Submissions($this->ctx()->db()))->get((int) $r->param('id'));
        $key  = $r->param('key');
        $n    = (int) $r->param('n');
        $file = $item['files'][$key][$n] ?? null;
        $path = \is_array($file) ? FileStore::resolve((string) ($file['path'] ?? '')) : null;
        if ($path === null) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return ['name' => (string) $file['name'], 'mime' => (string) $file['mime'], 'size' => (int) $file['size'], 'data' => base64_encode((string) file_get_contents($path))];
    }

    /** @return array<string,mixed> */
    private function requireForm(int $id): array
    {
        $form = $id > 0 ? (new Forms($this->ctx()->db()))->get($id) : null;
        if ($form === null) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $form;
    }

    /** @return array<string,mixed> */
    private function persist(int $id, ApiRequest $r): array
    {
        $title = Fields::text($r->input('title', ''), 190);
        if ($title === '') {
            throw new ApiException(Text::_('UXS_FORMS_ERR_TITLE'), 422, 'invalid_title');
        }
        $input = $r->body();
        $fields = Fields::sanitizeList($input['fields'] ?? []);
        if (!array_filter($fields, static fn ($f) => !\in_array($f['type'], Fields::LAYOUT, true))) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_NO_FIELDS'), 422, 'no_fields');
        }
        $saved = (new Forms($this->ctx()->db()))->save($id, $input);

        return ['form' => $this->requireForm($saved)];
    }
}

<?php

namespace Ux1\UxStudio\Modules\ExitPopup;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\PopupManager\Support;

\defined('_JEXEC') or die;

/**
 * Exit Popup: one popup with an optional e-mail capture form and autoresponder, shown when the visitor is about to leave
 * (mouse leaves the window, tab change, window blur, inactivity, fast scroll up) or after N seconds on the page.
 * Targeting by menu item / URL / device / audience, scheduling, frequency capping. Reuses Support from popup-manager.
 */
final class Module extends BaseModule
{
    private bool $render = false;

    private bool $preview = false;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'prepare']);
            $ctx->on('onAfterRender', [$this, 'inject'], Priority::LOW);
        }
    }

    public function prepare(Event $event): void
    {
        $this->render = false;
        if (!Support::isPageView()) {
            return;
        }
        $ctx = $this->ctx();
        if (Support::previewRequested('exit') > 0) {
            $this->render = $this->preview = true;
        } else {
            $this->render = $this->shouldShow($ctx->settings());
        }
        if ($this->render) {
            $ctx->useStyle('exit-popup.css');
            $ctx->useScript('exit-popup.js');
        }
    }

    /** @param array<string,mixed> $s */
    private function shouldShow(array $s): bool
    {
        if (empty($s['active'])) {
            return false;
        }
        $app   = $this->ctx()->app();
        $guest = (bool) ($app->getIdentity()?->guest ?? true);
        $aud   = (string) ($s['audience'] ?? 'all');
        if (($aud === 'guests' && !$guest) || ($aud === 'members' && $guest)) {
            return false;
        }
        $now   = time();
        $from  = Support::localToTimestamp($s['starts_at'] ?? '');
        $until = Support::localToTimestamp($s['ends_at'] ?? '');
        if (($from && $now < $from) || ($until && $now > $until)) {
            return false;
        }

        return Support::pageMatches(
            (string) ($s['targeting_mode'] ?? 'everywhere') === 'everywhere' ? 'all' : (string) $s['targeting_mode'],
            Support::ids($s['menu_items'] ?? []),
            Support::splitLines((string) ($s['url_patterns'] ?? '')),
            Support::activeMenuItem($app),
            Uri::getInstance()->toString(['path', 'query'])
        );
    }

    public function inject(Event $event): void
    {
        if (!$this->render) {
            return;
        }
        $app  = $this->ctx()->app();
        $body = $app->getBody();
        $pos  = \is_string($body) ? strripos($body, '</body>') : false;
        if ($pos === false) {
            return;
        }
        $s    = $this->ctx()->settings();
        $html = Renderer::html($s, ['preview' => $this->preview, 'storage' => (string) ($s['storage'] ?? 'local')]) . "\n";
        $app->setBody(substr($body, 0, $pos) . $html . substr($body, $pos));
    }

    public function settingsSchema(): array
    {
        $content = 'UXS_EXIT_POPUP_SEC_CONTENT';
        $look    = 'UXS_EXIT_POPUP_SEC_APPEARANCE';
        $trigger = 'UXS_EXIT_POPUP_SEC_TRIGGERS';
        $target  = 'UXS_EXIT_POPUP_SEC_TARGETING';
        $mail    = 'UXS_EXIT_POPUP_SEC_EMAIL';
        $cta     = ['key' => 'enable_cta', 'truthy' => true];
        $send    = ['key' => 'enable_email', 'truthy' => true];

        // Menu items are only needed by the administration form; the site never lists them (no query per page view).
        $menu = $this->ctx()->isAdmin() || $this->ctx()->client() === 'cli' ? Support::menuOptions($this->ctx()->db()) : [];

        $f = static fn (string $key, string $type, string $section, string $label, array $extra = []): array => ['key' => $key, 'type' => $type, 'section' => $section, 'label' => $label] + $extra;

        return [
            $f('active', 'toggle', $content, 'UXS_EXIT_POPUP_F_ACTIVE', ['default' => false, 'help' => 'UXS_EXIT_POPUP_F_ACTIVE_HELP']),
            $f('headline', 'text', $content, 'UXS_EXIT_POPUP_F_HEADLINE', ['help' => 'UXS_EXIT_POPUP_F_HEADLINE_HELP', 'maxlength' => 300]),
            $f('body', 'richtext', $content, 'UXS_EXIT_POPUP_F_BODY', ['help' => 'UXS_EXIT_POPUP_F_BODY_HELP', 'default' => '', 'maxlength' => 20000]),
            $f('image', 'media', $content, 'UXS_EXIT_POPUP_F_IMAGE', ['help' => 'UXS_EXIT_POPUP_F_IMAGE_HELP']),
            $f('image_alt', 'text', $content, 'UXS_EXIT_POPUP_F_IMAGE_ALT', ['maxlength' => 200]),
            $f('layout', 'select', $content, 'UXS_EXIT_POPUP_F_LAYOUT', ['default' => 'vertical', 'options' => ['vertical' => 'UXS_EXIT_POPUP_LAYOUT_V', 'horizontal' => 'UXS_EXIT_POPUP_LAYOUT_H']]),
            $f('enable_cta', 'toggle', $content, 'UXS_EXIT_POPUP_F_ENABLE_CTA', ['default' => false]),
            $f('cta_text', 'text', $content, 'UXS_EXIT_POPUP_F_CTA_TEXT', ['show_if' => $cta, 'maxlength' => 80]),
            $f('cta_url', 'text', $content, 'UXS_EXIT_POPUP_F_CTA_URL', ['show_if' => $cta, 'help' => 'UXS_EXIT_POPUP_F_CTA_URL_HELP', 'default' => '#', 'maxlength' => 500]),
            $f('cta_new_tab', 'toggle', $content, 'UXS_EXIT_POPUP_F_CTA_NEW_TAB', ['show_if' => $cta, 'default' => false]),

            $f('bg_color', 'color', $look, 'UXS_EXIT_POPUP_F_BG', ['default' => '#ffffff']),
            $f('title_color', 'color', $look, 'UXS_EXIT_POPUP_F_TITLE_COLOR', ['default' => '#1f2937']),
            $f('text_color', 'color', $look, 'UXS_EXIT_POPUP_F_TEXT_COLOR', ['default' => '#4b5563']),
            $f('cta_bg_color', 'color', $look, 'UXS_EXIT_POPUP_F_BUTTON_BG', ['default' => '#2563eb']),
            $f('cta_text_color', 'color', $look, 'UXS_EXIT_POPUP_F_BUTTON_TEXT', ['default' => '#ffffff']),
            $f('overlay_opacity', 'number', $look, 'UXS_EXIT_POPUP_F_OVERLAY', ['default' => 60, 'min' => 0, 'max' => 100]),
            $f('width', 'number', $look, 'UXS_EXIT_POPUP_F_WIDTH', ['default' => 480, 'min' => 320, 'max' => 900, 'step' => 10]),

            $f('delay_seconds', 'number', $trigger, 'UXS_EXIT_POPUP_F_DELAY', ['help' => 'UXS_EXIT_POPUP_F_DELAY_HELP', 'default' => 2, 'min' => 0, 'max' => 600]),
            $f('frequency_mode', 'select', $trigger, 'UXS_EXIT_POPUP_F_FREQUENCY', [
                'help' => 'UXS_EXIT_POPUP_F_FREQUENCY_HELP', 'default' => 'session',
                'options' => ['session' => 'UXS_EXIT_POPUP_FREQ_SESSION', 'days' => 'UXS_EXIT_POPUP_FREQ_DAYS', 'once' => 'UXS_EXIT_POPUP_FREQ_ONCE', 'always' => 'UXS_EXIT_POPUP_FREQ_ALWAYS'],
            ]),
            $f('frequency_days', 'number', $trigger, 'UXS_EXIT_POPUP_F_FREQ_DAYS', ['show_if' => ['key' => 'frequency_mode', 'equals' => 'days'], 'default' => 7, 'min' => 1, 'max' => 365]),
            $f('storage', 'select', $trigger, 'UXS_EXIT_POPUP_F_STORAGE', [
                'help' => 'UXS_EXIT_POPUP_F_STORAGE_HELP', 'default' => 'local',
                'show_if' => ['key' => 'frequency_mode', 'in' => ['days', 'once']],
                'options' => ['local' => 'UXS_EXIT_POPUP_STORAGE_LOCAL', 'cookie' => 'UXS_EXIT_POPUP_STORAGE_COOKIE'],
            ]),
            $f('detect_mouse_leave', 'toggle', $trigger, 'UXS_EXIT_POPUP_F_DET_MOUSE', ['default' => true, 'help' => 'UXS_EXIT_POPUP_F_DET_MOUSE_HELP']),
            $f('mouse_leave_delay', 'number', $trigger, 'UXS_EXIT_POPUP_F_DET_MOUSE_DELAY', ['show_if' => ['key' => 'detect_mouse_leave', 'truthy' => true], 'default' => 500, 'min' => 0, 'max' => 5000, 'step' => 50]),
            $f('detect_tab_change', 'toggle', $trigger, 'UXS_EXIT_POPUP_F_DET_TAB', ['default' => false, 'help' => 'UXS_EXIT_POPUP_F_DET_TAB_HELP']),
            $f('detect_window_blur', 'toggle', $trigger, 'UXS_EXIT_POPUP_F_DET_BLUR', ['default' => false, 'help' => 'UXS_EXIT_POPUP_F_DET_BLUR_HELP']),
            $f('detect_idle', 'toggle', $trigger, 'UXS_EXIT_POPUP_F_DET_IDLE', ['default' => false]),
            $f('idle_timeout', 'number', $trigger, 'UXS_EXIT_POPUP_F_DET_IDLE_TIMEOUT', ['show_if' => ['key' => 'detect_idle', 'truthy' => true], 'default' => 30, 'min' => 1, 'max' => 3600]),
            $f('detect_scroll_up', 'toggle', $trigger, 'UXS_EXIT_POPUP_F_DET_SCROLL', ['default' => false, 'help' => 'UXS_EXIT_POPUP_F_DET_SCROLL_HELP']),
            $f('scroll_up_desktop', 'number', $trigger, 'UXS_EXIT_POPUP_F_DET_SCROLL_DESKTOP', ['show_if' => ['key' => 'detect_scroll_up', 'truthy' => true], 'default' => 400, 'min' => 50, 'max' => 5000, 'step' => 10]),
            $f('scroll_up_mobile', 'number', $trigger, 'UXS_EXIT_POPUP_F_DET_SCROLL_MOBILE', ['show_if' => ['key' => 'detect_scroll_up', 'truthy' => true], 'default' => 200, 'min' => 50, 'max' => 5000, 'step' => 10]),
            $f('time_on_page', 'number', $trigger, 'UXS_EXIT_POPUP_F_TIME_ON_PAGE', ['help' => 'UXS_EXIT_POPUP_F_TIME_ON_PAGE_HELP', 'default' => 0, 'min' => 0, 'max' => 3600]),

            $f('targeting_mode', 'select', $target, 'UXS_EXIT_POPUP_F_TARGETING', [
                'default' => 'everywhere',
                'options' => ['everywhere' => 'UXS_EXIT_POPUP_TARGET_ALL', 'include' => 'UXS_EXIT_POPUP_TARGET_INCLUDE', 'exclude' => 'UXS_EXIT_POPUP_TARGET_EXCLUDE'],
            ]),
            $f('menu_items', 'multiselect', $target, 'UXS_EXIT_POPUP_F_MENU_ITEMS', ['show_if' => ['key' => 'targeting_mode', 'in' => ['include', 'exclude']], 'options' => $menu, 'default' => [], 'help' => 'UXS_EXIT_POPUP_F_MENU_ITEMS_HELP']),
            $f('url_patterns', 'textarea', $target, 'UXS_EXIT_POPUP_F_URL_PATTERNS', ['show_if' => ['key' => 'targeting_mode', 'in' => ['include', 'exclude']], 'help' => 'UXS_EXIT_POPUP_F_URL_PATTERNS_HELP', 'default' => '', 'maxlength' => 2000]),
            $f('devices', 'multiselect', $target, 'UXS_EXIT_POPUP_F_DEVICES', ['default' => [], 'help' => 'UXS_EXIT_POPUP_F_DEVICES_HELP', 'options' => ['desktop' => 'UXS_EXIT_POPUP_DEV_DESKTOP', 'tablet' => 'UXS_EXIT_POPUP_DEV_TABLET', 'mobile' => 'UXS_EXIT_POPUP_DEV_MOBILE']]),
            $f('audience', 'select', $target, 'UXS_EXIT_POPUP_F_AUDIENCE', ['default' => 'all', 'options' => ['all' => 'UXS_EXIT_POPUP_AUD_ALL', 'guests' => 'UXS_EXIT_POPUP_AUD_GUESTS', 'members' => 'UXS_EXIT_POPUP_AUD_MEMBERS']]),
            $f('starts_at', 'datetime', $target, 'UXS_EXIT_POPUP_F_STARTS', ['help' => 'UXS_EXIT_POPUP_F_SCHEDULE_HELP']),
            $f('ends_at', 'datetime', $target, 'UXS_EXIT_POPUP_F_ENDS'),

            $f('enable_email', 'toggle', $mail, 'UXS_EXIT_POPUP_F_ENABLE_EMAIL', ['default' => true, 'help' => 'UXS_EXIT_POPUP_F_ENABLE_EMAIL_HELP']),
            $f('email_placeholder', 'text', $mail, 'UXS_EXIT_POPUP_F_EMAIL_PLACEHOLDER', ['show_if' => $send, 'maxlength' => 100]),
            $f('email_button_text', 'text', $mail, 'UXS_EXIT_POPUP_F_EMAIL_BUTTON', ['show_if' => $send, 'maxlength' => 60]),
            $f('email_success_message', 'text', $mail, 'UXS_EXIT_POPUP_F_EMAIL_SUCCESS', ['show_if' => $send, 'maxlength' => 200]),
            $f('consent_text', 'text', $mail, 'UXS_EXIT_POPUP_F_CONSENT', ['show_if' => $send, 'help' => 'UXS_EXIT_POPUP_F_CONSENT_HELP', 'maxlength' => 300]),
            $f('enable_autoresponder', 'toggle', $mail, 'UXS_EXIT_POPUP_F_AUTORESPONDER', ['show_if' => $send, 'default' => false, 'help' => 'UXS_EXIT_POPUP_F_AUTORESPONDER_HELP']),
            $f('autoresponder_subject', 'text', $mail, 'UXS_EXIT_POPUP_F_AR_SUBJECT', ['show_if' => [$send, ['key' => 'enable_autoresponder', 'truthy' => true]], 'maxlength' => 200]),
            $f('autoresponder_body', 'richtext', $mail, 'UXS_EXIT_POPUP_F_AR_BODY', ['show_if' => [$send, ['key' => 'enable_autoresponder', 'truthy' => true]], 'default' => '', 'maxlength' => 20000]),
            $f('subscribers_retention_days', 'number', $mail, 'UXS_EXIT_POPUP_F_RETENTION', ['show_if' => $send, 'help' => 'UXS_EXIT_POPUP_F_RETENTION_HELP', 'default' => 0, 'min' => 0, 'max' => 3650]),
        ];
    }

    public function routes(): array
    {
        $api = new Api($this->ctx());

        return [
            'GET subscribers'                  => [$api, 'list'],
            'GET subscribers/export'           => ['handler' => [$api, 'export'], 'acl' => 'core.admin'],
            'POST subscribers/purge'           => ['handler' => [$api, 'purge'], 'acl' => 'core.admin'],
            'POST subscribers/{id}/delete'     => ['handler' => [$api, 'delete'], 'acl' => 'core.admin'],
            'GET preview-url'                  => [$api, 'previewUrl'],
        ];
    }

    public function publicRoutes(): array
    {
        $api = new Api($this->ctx());

        // Browser form: no auth, a present Origin must be this site, 5 attempts per 10 minutes and IP.
        return ['POST subscribe' => ['handler' => [$api, 'subscribe'], 'auth' => 'none', 'limit' => [5, 600], 'max_body' => 4096]];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_exit_popup_emails` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `email` VARCHAR(254) NOT NULL, '
                . '`page_url` VARCHAR(500) NOT NULL DEFAULT \'\', `ip_hash` VARCHAR(64) NOT NULL DEFAULT \'\', `consent` TINYINT(1) NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uq_email` (`email`), KEY `idx_created` (`created`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_exit_popup_emails', 'column' => 'created', 'days' => fn (): int => (int) $this->ctx()->setting('subscribers_retention_days', 0)]];
    }

    public function dashboardWidget(): ?array
    {
        $count = (new Subscribers($this->ctx()->db()))->count();

        return ['title' => Text::_('UXS_EXIT_POPUP_NAME'), 'value' => $count, 'text' => Text::_('UXS_EXIT_POPUP_WIDGET'), 'status' => 'ok'];
    }
}

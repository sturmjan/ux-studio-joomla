<?php

namespace Ux1\UxStudio\Modules\ThirdPartyLogin;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Third-party login: sign in to the administration and the website with Google, Microsoft, Facebook, Seznam or any
 * OpenID Connect provider. OAuth 2.0 authorization code flow with PKCE, state and nonce (Flow.php); the buttons are plain
 * links added to the Joomla login forms (CSP friendly, no script). Accounts are paired by the provider identity, then by
 * a VERIFIED e-mail address; creating accounts is opt-in. See docs/modules/third-party-login.md.
 */
final class Module extends BaseModule
{
    /** @var array<int|string,string>|null */
    private static ?array $groups = null;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite() || $ctx->isAdmin()) {
            // Both endpoints run in onAfterInitialise, before Joomla routes the request, and always end the request.
            $query = $ctx->app()->getInput()->get->getCmd(Flow::QUERY, '');
            if ($query === 'start') {
                (new Flow($ctx, $this->store()))->start();
            } elseif (Flow::isCallbackRequest()) {
                (new Flow($ctx, $this->store()))->callback();
            }

            $ctx->on('onAfterRender', [$this, 'injectButtons'], Priority::LOW);
        }
        $ctx->on('onUserAfterDelete', [$this, 'userDeleted']);
    }

    private function store(): Store
    {
        return new Store($this->ctx()->db());
    }

    public function acl(): string
    {
        return 'core.admin';
    }

    /* ---------------------------------------------------------------- login form buttons */

    public function injectButtons(Event $event): void
    {
        $ctx = $this->ctx();
        $app = $ctx->app();
        $me  = $app->getIdentity();
        if (($me && !$me->guest) || !$app->getDocument() instanceof HtmlDocument) {
            return;
        }
        $body = $app->getBody();
        // Cheap test first: only pages that carry a Joomla login form need any work.
        if (!preg_match('~<form\b[^>]*\bid="(?:form-login|com-users-login__form|login-form[^"]*)"[^>]*>~i', $body)) {
            return;
        }

        $area = $ctx->isAdmin() ? 'administrator' : 'site';
        $flow = new Flow($ctx, $this->store());
        if (!$flow->areaEnabled($area)) {
            return;
        }
        $providers = $flow->providers();
        if (!$providers) {
            return;
        }

        $html = $this->buttonsHtml($area, $providers, str_contains($body, 'com-users-login__form'));
        $new  = preg_replace_callback(
            '~(<form\b[^>]*\bid="(?:form-login|com-users-login__form|login-form[^"]*)"[^>]*>.*?</form>)~is',
            static fn (array $m): string => $m[1] . $html,
            $body,
            1
        );
        if (\is_string($new)) {
            $app->setBody($new);
        }
    }

    /** @param array<string,array<string,mixed>> $providers */
    private function buttonsHtml(string $area, array $providers, bool $loginPage): string
    {
        $base = ($area === 'administrator' ? Uri::root() . 'administrator/' : Uri::root()) . 'index.php';
        $return = '';
        if ($area === 'site') {
            $current = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
            if ($loginPage) {
                // Keep the return target com_users carries (base64) so a visitor sent here from a protected page lands back there.
                $return = (string) $this->ctx()->app()->getInput()->getBase64('return', '');
            } else {
                $return = base64_encode($current);
            }
        }
        $css = $this->ctx()->assetUrl('login.css') . '?v=' . substr(md5((string) @filemtime(JPATH_ROOT . '/media/lib_uxstudio/modules/third-party-login/login.css')), 0, 8);

        $out = '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="uxs-tpl" role="group" aria-label="' . htmlspecialchars(Text::_('UXS_THIRD_PARTY_LOGIN_GROUP'), ENT_QUOTES, 'UTF-8') . '">'
            . '<p class="uxs-tpl__or"><span>' . htmlspecialchars(Text::_('UXS_THIRD_PARTY_LOGIN_OR'), ENT_QUOTES, 'UTF-8') . '</span></p>';
        foreach ($providers as $id => $def) {
            $href = $base . '?' . http_build_query(['uxs_tpl' => 'start', 'provider' => $id] + ($return !== '' ? ['return' => $return] : []), '', '&', PHP_QUERY_RFC3986);
            $out .= '<a class="uxs-tpl__btn uxs-tpl__btn--' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="nofollow">'
                . self::icon($id) . '<span>' . htmlspecialchars(Text::sprintf('UXS_THIRD_PARTY_LOGIN_SIGN_IN_WITH', (string) $def['label']), ENT_QUOTES, 'UTF-8') . '</span></a>';
        }

        return $out . '</div>';
    }

    /** Inline SVG logos (presentation attributes only: no style attribute, so a strict CSP is fine). */
    private static function icon(string $id): string
    {
        $svg = static fn (string $inner): string => '<svg class="uxs-tpl__icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';

        return match ($id) {
            'google' => $svg('<path fill="#4285F4" d="M23.5 12.27c0-.85-.08-1.67-.22-2.45H12v4.63h6.45a5.52 5.52 0 0 1-2.39 3.62v3h3.87c2.27-2.09 3.57-5.17 3.57-8.8z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.87-3c-1.07.72-2.45 1.15-4.08 1.15-3.13 0-5.78-2.11-6.73-4.96H1.27v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.28A7.2 7.2 0 0 1 4.9 12c0-.79.14-1.56.37-2.28V6.63H1.27A12 12 0 0 0 0 12c0 1.94.46 3.77 1.27 5.37l4-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.43-3.43C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.27 6.63l4 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>'),
            'microsoft' => $svg('<rect x="1" y="1" width="10" height="10" fill="#F25022"/><rect x="13" y="1" width="10" height="10" fill="#7FBA00"/><rect x="1" y="13" width="10" height="10" fill="#00A4EF"/><rect x="13" y="13" width="10" height="10" fill="#FFB900"/>'),
            'facebook' => $svg('<circle cx="12" cy="12" r="12" fill="#1877F2"/><path fill="#fff" d="M16.67 15.47 17.2 12h-3.33V9.75c0-.95.47-1.88 1.96-1.88h1.51V4.92s-1.37-.23-2.68-.23c-2.74 0-4.53 1.66-4.53 4.67V12H7.1v3.47h3.03V24h3.74v-8.53z"/>'),
            'seznam' => $svg('<rect width="24" height="24" rx="5" fill="#CC0000"/><path fill="#fff" d="M15.9 9.1c0-1.4-1.5-2.4-3.8-2.4-2.4 0-3.9 1.1-3.9 2.8 0 1.4.9 2.2 3 2.7l1.5.4c.8.2 1.1.4 1.1.8 0 .5-.5.8-1.3.8-.9 0-1.5-.4-1.6-1.1H8.1c.1 1.6 1.6 2.6 3.9 2.6 2.4 0 3.9-1.1 3.9-2.8 0-1.4-.9-2.2-3-2.7l-1.5-.4c-.8-.2-1.1-.4-1.1-.8s.4-.7 1.2-.7c.8 0 1.4.4 1.5 1z" transform="translate(0 1)"/>'),
            default => $svg('<circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" stroke-width="2"/><path fill="currentColor" d="M12 6a3 3 0 0 0-1 5.8V18h2v-2h2v-2h-2v-2.2A3 3 0 0 0 12 6zm0 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>'),
        };
    }

    public function userDeleted(Event $event): void
    {
        // J5/J6 AfterDeleteEvent carries the deleted user as 'subject' (older events used 'user').
        $user = $event->getArgument('subject', $event->getArgument('user'));
        $id   = \is_array($user) ? (int) ($user['id'] ?? 0) : (int) (\is_object($user) ? ($user->id ?? 0) : 0);
        if ($id > 0) {
            $this->store()->deleteForUser($id);
        }
    }

    /* ---------------------------------------------------------------- settings */

    /** @return array<int|string,string> id => title of the Joomla user groups */
    private function groupOptions(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }
        self::$groups = [];
        try {
            $db = $this->ctx()->db();
            foreach ($db->setQuery($db->createQuery()->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__usergroups'))->where($db->quoteName('id') . ' > 1')->order($db->quoteName('lft')))->loadAssocList() ?: [] as $g) {
                self::$groups[(int) $g['id']] = (string) $g['title'];
            }
        } catch (\Throwable $e) {
            // Before the first migration / without a database the list is simply empty.
        }

        return self::$groups;
    }

    public function settingsSchema(): array
    {
        $g = 'UXS_THIRD_PARTY_LOGIN_SECTION_GENERAL';
        $p = fn (string $id, string $labelKey): array => [
            ['key' => $id . '_enabled', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_ENABLE', 'default' => false, 'section' => $labelKey],
            ['key' => $id . '_client_id', 'type' => 'text', 'label' => 'UXS_THIRD_PARTY_LOGIN_CLIENT_ID', 'help' => 'UXS_THIRD_PARTY_LOGIN_CLIENT_ID_HELP', 'default' => '', 'maxlength' => 255, 'section' => $labelKey],
            ['key' => $id . '_client_secret', 'type' => 'password', 'label' => 'UXS_THIRD_PARTY_LOGIN_CLIENT_SECRET', 'help' => 'UXS_THIRD_PARTY_LOGIN_CLIENT_SECRET_HELP', 'section' => $labelKey],
        ];

        $schema = [
            ['key' => 'admin_login', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_ADMIN_LOGIN', 'help' => 'UXS_THIRD_PARTY_LOGIN_ADMIN_LOGIN_HELP', 'default' => true, 'section' => $g],
            ['key' => 'site_login', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_SITE_LOGIN', 'help' => 'UXS_THIRD_PARTY_LOGIN_SITE_LOGIN_HELP', 'default' => true, 'section' => $g],
            ['key' => 'match_by_email', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_MATCH', 'help' => 'UXS_THIRD_PARTY_LOGIN_MATCH_HELP', 'default' => true, 'section' => $g],
            ['key' => 'allow_privileged', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_PRIVILEGED', 'help' => 'UXS_THIRD_PARTY_LOGIN_PRIVILEGED_HELP', 'default' => false, 'section' => $g],
            ['key' => 'allowed_groups', 'type' => 'multiselect', 'label' => 'UXS_THIRD_PARTY_LOGIN_GROUPS', 'help' => 'UXS_THIRD_PARTY_LOGIN_GROUPS_HELP', 'default' => [], 'options' => $this->groupOptions(), 'section' => $g],
            ['key' => 'allowed_domains', 'type' => 'textarea', 'label' => 'UXS_THIRD_PARTY_LOGIN_DOMAINS', 'help' => 'UXS_THIRD_PARTY_LOGIN_DOMAINS_HELP', 'default' => '', 'maxlength' => 1000, 'section' => $g],
            ['key' => 'auto_create', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_CREATE', 'help' => 'UXS_THIRD_PARTY_LOGIN_CREATE_HELP', 'default' => false, 'section' => $g],
            ['key' => 'auto_create_group', 'type' => 'select', 'label' => 'UXS_THIRD_PARTY_LOGIN_CREATE_GROUP', 'help' => 'UXS_THIRD_PARTY_LOGIN_CREATE_GROUP_HELP', 'default' => '2',
             'options' => $this->groupOptions() ?: ['2' => 'Registered'], 'section' => $g, 'show_if' => ['key' => 'auto_create', 'truthy' => true]],
        ];
        $schema = array_merge($schema, $p('google', 'Google'), $p('microsoft', 'Microsoft'));
        $schema[] = ['key' => 'microsoft_tenant', 'type' => 'text', 'label' => 'UXS_THIRD_PARTY_LOGIN_MS_TENANT', 'help' => 'UXS_THIRD_PARTY_LOGIN_MS_TENANT_HELP', 'default' => 'common', 'maxlength' => 100, 'section' => 'Microsoft'];
        $schema = array_merge($schema, $p('facebook', 'Facebook'));
        $schema[] = ['key' => 'facebook_trust_email', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_FB_TRUST', 'help' => 'UXS_THIRD_PARTY_LOGIN_FB_TRUST_HELP', 'default' => false, 'section' => 'Facebook'];
        $schema = array_merge($schema, $p('seznam', 'Seznam'), $p('oidc', 'OpenID Connect'));
        $oidc = 'OpenID Connect';
        foreach ([
            ['oidc_label', 'text', 'LABEL', 'OpenID Connect', 100],
            ['oidc_authorize_url', 'text', 'AUTHORIZE_URL', '', 500],
            ['oidc_token_url', 'text', 'TOKEN_URL', '', 500],
            ['oidc_userinfo_url', 'text', 'USERINFO_URL', '', 500],
            ['oidc_issuer', 'text', 'ISSUER', '', 500],
        ] as [$key, $type, $suffix, $default, $max]) {
            $schema[] = ['key' => $key, 'type' => $type, 'label' => 'UXS_THIRD_PARTY_LOGIN_OIDC_' . $suffix, 'help' => 'UXS_THIRD_PARTY_LOGIN_OIDC_' . $suffix . '_HELP', 'default' => $default, 'maxlength' => $max, 'section' => $oidc];
        }
        $schema[] = ['key' => 'oidc_trust_email', 'type' => 'toggle', 'label' => 'UXS_THIRD_PARTY_LOGIN_OIDC_TRUST', 'help' => 'UXS_THIRD_PARTY_LOGIN_OIDC_TRUST_HELP', 'default' => false, 'section' => $oidc];

        return $schema;
    }

    /* ---------------------------------------------------------------- data */

    public function routes(): array
    {
        $api = new Api($this->ctx(), $this->store());

        return [
            'GET status'                       => [$api, 'status'],
            'GET identities'                   => [$api, 'identities'],
            'GET log'                          => [$api, 'log'],
            'POST link/{provider}'             => [$api, 'link'],
            'POST identities/{id}/unlink'      => [$api, 'unlink'],
        ];
    }

    public function migrations(): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_tpl_identities` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `provider` VARCHAR(20) NOT NULL, '
                . '`subject` VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `email` VARCHAR(190) NOT NULL DEFAULT \'\', `created` DATETIME NOT NULL, `last_login` DATETIME NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_identity` (`provider`, `subject`), KEY `idx_user` (`user_id`)) ' . $opts,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_tpl_log` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `event` VARCHAR(30) NOT NULL, `provider` VARCHAR(20) NOT NULL DEFAULT \'\', '
                . '`area` VARCHAR(14) NOT NULL DEFAULT \'\', `user_id` INT NOT NULL DEFAULT 0, `detail` VARCHAR(250) NOT NULL DEFAULT \'\', `ip` VARCHAR(45) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`)) ' . $opts,
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_tpl_log', 'column' => 'created', 'days' => 90]];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $count = $this->store()->countIdentities();
            $flow  = new Flow($this->ctx(), $this->store());
            $on    = \count($flow->providers());
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => Text::_('UXS_THIRD_PARTY_LOGIN_NAME'),
            'value'  => $count,
            'text'   => Text::sprintf('UXS_THIRD_PARTY_LOGIN_WIDGET', $on),
            'status' => $on > 0 ? 'ok' : 'warn',
        ];
    }
}

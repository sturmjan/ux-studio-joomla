<?php

namespace Ux1\UxStudio\Modules\ThirdPartyLogin;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Event\User\AfterLoginEvent;
use Joomla\CMS\Event\User\LoginEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * The OAuth 2.0 authorization code flow with PKCE (S256), state and nonce, for the administrator and the site application.
 *
 *   start    index.php?uxs_tpl=start&provider=<id>[&mode=link&<csrf token>=1][&return=<base64 url>]
 *   callback index.php/uxs-oauth/callback          (the redirect URI registered at the provider; path based, no query string)
 *
 * Each application handles its own flow because the administrator and the site have separate sessions: the state, the PKCE
 * verifier and the nonce live only in the session of the browser that started the flow (login CSRF protection).
 * Both endpoints run in onAfterInitialise, before Joomla routes the request, and always end the request.
 */
final class Flow
{
    public const QUERY = 'uxs_tpl';

    private const TTL         = 600;
    private const SESSION_KEY = 'uxs_tpl_flows';
    private const CALLBACK    = 'uxs-oauth/callback';

    /** Groups an automatically created account must never get. */
    private const ADMIN_ACTIONS = [['core.admin', null], ['core.admin', 'com_config'], ['core.manage', 'com_installer'], ['core.manage', 'com_users'], ['core.manage', 'com_plugins'], ['core.admin', 'com_plugins'], ['core.manage', 'com_templates'], ['core.edit', 'com_templates']];

    public function __construct(private ModuleContext $ctx, private Store $store)
    {
    }

    /* ------------------------------------------------------------------ helpers */

    public static function isCallbackRequest(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return (bool) preg_match('#/' . preg_quote(self::CALLBACK, '#') . '/?$#', $path);
    }

    /** Redirect URI to register at the provider; one per application. */
    public static function redirectUri(string $area): string
    {
        return Uri::root() . ($area === 'administrator' ? 'administrator/' : '') . 'index.php/' . self::CALLBACK;
    }

    private function area(): string
    {
        return $this->ctx->isAdmin() ? 'administrator' : 'site';
    }

    private function ip(): string
    {
        return class_exists(ClientIp::class) ? ClientIp::current() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function areaEnabled(string $area): bool
    {
        return (bool) $this->ctx->setting($area === 'administrator' ? 'admin_login' : 'site_login', true);
    }

    /**
     * Configured and enabled providers for an application.
     *
     * @return array<string,array<string,mixed>> id => definition
     */
    public function providers(): array
    {
        $settings = $this->ctx->settings();
        $out      = [];
        foreach (Providers::IDS as $id) {
            if (empty($settings[$id . '_enabled'])) {
                continue;
            }
            $def = Providers::definition($id, $settings);
            if ($def !== null) {
                $out[$id] = $def;
            }
        }

        return $out;
    }

    private function loginUrl(): string
    {
        return $this->area() === 'administrator' ? Uri::root() . 'administrator/index.php' : Route::_('index.php?option=com_users&view=login', false);
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function fail(string $reason, string $provider = '', int $userId = 0, string $detail = '', string $to = ''): never
    {
        $this->store->log('login_failed', $provider, $this->area(), $userId, $reason . ($detail !== '' ? ': ' . $detail : ''), $this->ip());
        $key = 'UXS_THIRD_PARTY_LOGIN_ERR_' . strtoupper($reason);
        $msg = Text::_($key);
        $app = $this->ctx->app();
        $app->enqueueMessage($msg === $key ? Text::_('UXS_THIRD_PARTY_LOGIN_ERR_FAILED') : $msg, 'error');
        $app->redirect($to !== '' ? $to : $this->loginUrl());
        exit;
    }

    /** Rate limit per client IP, checked before anything is logged or validated; over the limit the request is dropped without a log row. */
    private function throttle(string $bucket, string $to = ''): void
    {
        if ((new RateLimiter($this->ctx->db()))->hitClient($bucket, 30, 600) > 0) {
            $app = $this->ctx->app();
            $app->enqueueMessage(Text::_('UXS_THIRD_PARTY_LOGIN_ERR_RATE'), 'error');
            $app->redirect($to !== '' ? $to : $this->loginUrl());
            exit;
        }
    }

    /* ------------------------------------------------------------------ start */

    public function start(): never
    {
        $app      = $this->ctx->app();
        $area     = $this->area();
        $input    = $app->getInput()->get;
        $provider = (string) $input->getCmd('provider', '');
        $mode     = $input->getCmd('mode', 'login') === 'link' ? 'link' : 'login';
        $identity = $app->getIdentity();
        $guest    = !$identity || $identity->guest;

        $this->throttle('tpl:start', $mode === 'link' ? Uri::root() . 'administrator/index.php?option=com_uxstudio#/module?id=third-party-login' : '');
        if ($mode === 'login' && !$guest) {
            $app->redirect($area === 'administrator' ? Uri::root() . 'administrator/index.php' : Uri::root());
            exit;
        }
        $linkBack = Uri::root() . 'administrator/index.php?option=com_uxstudio#/module?id=third-party-login';
        if ($mode === 'link') {
            // Linking is a state change of an authenticated user: needs the session and the form token (CSRF).
            if ($area !== 'administrator' || $guest || !Session::checkToken('get')) {
                $this->fail('session', $provider, 0, '', $linkBack);
            }
        } elseif (!$this->areaEnabled($area)) {
            $this->fail('disabled', $provider);
        }
        $def = $this->providers()[$provider] ?? null;
        if ($def === null) {
            $this->fail('provider', $provider, 0, '', $mode === 'link' ? $linkBack : '');
        }

        $state    = bin2hex(random_bytes(24));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = self::b64url(random_bytes(48));
        $return   = '';
        if ($mode === 'login' && $area === 'site') {
            $return = $this->safeSiteReturn((string) $input->getString('return', ''));
        }

        $session = $app->getSession();
        $flows   = array_filter((array) $session->get(self::SESSION_KEY, []), static fn ($f): bool => \is_array($f) && (int) ($f['t'] ?? 0) > time() - self::TTL);
        $flows   = \array_slice($flows, -4, 4, true); // a handful of parallel tabs, not an unbounded session
        $flows[$state] = ['provider' => $provider, 'mode' => $mode, 'area' => $area, 'nonce' => $nonce, 'verifier' => $verifier, 'return' => $return, 't' => time(), 'user' => $mode === 'link' ? (int) $identity->id : 0];
        $session->set(self::SESSION_KEY, $flows);

        $query = [
            'response_type'         => 'code',
            'client_id'             => $def['client_id'],
            'redirect_uri'          => self::redirectUri($area),
            'scope'                 => $def['scope'],
            'state'                 => $state,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ] + $def['extra'];
        if (!empty($def['oidc'])) {
            $query['nonce'] = $nonce;
        }
        if ($provider === 'google' && $mode === 'login') {
            $query['prompt'] = 'select_account';
        }

        $app->redirect($def['authorize'] . (str_contains($def['authorize'], '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    /** A same-site return target (base64 like com_users uses), '' otherwise. */
    private function safeSiteReturn(string $raw): string
    {
        $url = $raw !== '' ? base64_decode($raw, true) : false;
        if ($url === false || $url === '' || \strlen($url) > 1000) {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            // A site-relative path (as com_users stores it): make it absolute so isInternal() can judge it.
            $url = Uri::getInstance()->toString(['scheme', 'host', 'port']) . $url;
        }
        if (!Uri::isInternal($url)) {
            return '';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_contains($path, 'uxs-oauth') ? '' : $url;
    }

    /* ------------------------------------------------------------------ callback */

    public function callback(): never
    {
        $app   = $this->ctx->app();
        $area  = $this->area();
        $input = $app->getInput()->get;
        $this->throttle('tpl:cb');
        $state = (string) $input->getString('state', '');

        $session = $app->getSession();
        $flows   = (array) $session->get(self::SESSION_KEY, []);
        $flow    = \is_string($state) && preg_match('/^[a-f0-9]{48}$/', $state) && isset($flows[$state]) && \is_array($flows[$state]) ? $flows[$state] : null;
        if ($flow !== null) {
            // Single use: the state is burned whatever happens next.
            unset($flows[$state]);
            $session->set(self::SESSION_KEY, $flows);
        }
        $linkBack = Uri::root() . 'administrator/index.php?option=com_uxstudio#/module?id=third-party-login';

        if ($flow === null || (int) $flow['t'] < time() - self::TTL || $flow['area'] !== $area) {
            $this->fail('state');
        }
        $provider = (string) $flow['provider'];
        $mode     = (string) $flow['mode'];
        $to       = $mode === 'link' ? $linkBack : '';

        $def = $this->providers()[$provider] ?? null;
        if ($def === null || ($mode === 'login' && !$this->areaEnabled($area))) {
            $this->fail('provider', $provider, 0, '', $to);
        }
        if ($input->getString('error', '') !== '') {
            $this->fail('denied', $provider, 0, mb_substr(preg_replace('/[^A-Za-z0-9_.: -]+/', '', (string) $input->getString('error', '')) ?? '', 0, 60), $to);
        }
        $code = (string) $input->getString('code', '');
        if ($code === '' || \strlen($code) > 2048) {
            $this->fail('invalid', $provider, 0, '', $to);
        }

        try {
            $identity = Providers::fetchIdentity($def, $this->ctx->http(), $code, self::redirectUri($area), (string) $flow['verifier'], (string) $flow['nonce']);
        } catch (\Throwable $e) {
            $reason = $e instanceof \RuntimeException && \in_array($e->getMessage(), ['token_exchange', 'id_token', 'nonce', 'identity'], true) ? $e->getMessage() : 'provider_error';
            $this->fail('provider_error', $provider, 0, $reason, $to);
        }

        if ($mode === 'link') {
            $this->link($provider, $identity, (int) $flow['user'], $linkBack);
        }
        $this->login($provider, $identity, (string) $flow['return']);
    }

    /** @param array{sub:string,email:string,verified:bool,name:string} $identity */
    private function link(string $provider, array $identity, int $expectedUser, string $back): never
    {
        $app = $this->ctx->app();
        $me  = $app->getIdentity();
        if (!$me || $me->guest || (int) $me->id !== $expectedUser || $expectedUser <= 0) {
            $this->fail('session', $provider, 0, '', $back);
        }
        $existing = $this->store->findIdentity($provider, $identity['sub']);
        if ($existing !== null && $existing['user_id'] !== $expectedUser) {
            $this->fail('taken', $provider, $expectedUser, '', $back);
        }
        if ($existing === null) {
            $this->store->addIdentity($expectedUser, $provider, $identity['sub'], $identity['email']);
        }
        $this->store->log('linked', $provider, 'administrator', $expectedUser, $identity['email'], $this->ip());
        $app->enqueueMessage(Text::sprintf('UXS_THIRD_PARTY_LOGIN_LINKED', $provider), 'message');
        $app->redirect($back);
        exit;
    }

    /* ------------------------------------------------------------------ login */

    /** @param array{sub:string,email:string,verified:bool,name:string} $identity */
    private function login(string $provider, array $identity, string $return): never
    {
        $area     = $this->area();
        $settings = $this->ctx->settings();
        $email    = $identity['email'];

        // Domain restriction applies to every sign-in, linked or not.
        $domains = $this->allowedDomains((string) ($settings['allowed_domains'] ?? ''));
        if ($domains) {
            $domain = $email !== '' ? substr((string) strrchr($email, '@'), 1) : '';
            if ($domain === '' || !\in_array($domain, $domains, true)) {
                $this->fail('domain', $provider, 0, $domain);
            }
        }

        $linked  = $this->store->findIdentity($provider, $identity['sub']);
        $user    = null;
        $created = false;
        $matched = false;
        if ($linked !== null) {
            $user = $this->loadUser($linked['user_id']);
            if (!$user->id) {
                // The Joomla user is gone: forget the stale link and continue as an unknown identity.
                $this->store->deleteIdentity($linked['id']);
                $linked = null;
                $user   = null;
            }
        }

        if ($user === null) {
            if ($email === '' || !$identity['verified']) {
                $this->fail('email_unverified', $provider, 0, $email === '' ? 'no email' : '');
            }
            $ids = $this->store->userIdsByEmail($email);
            if (\count($ids) > 1) {
                $this->fail('ambiguous', $provider, 0, $email);
            }
            if ($ids) {
                if (!$settings['match_by_email']) {
                    $this->fail('no_account', $provider, $ids[0], 'matching by e-mail is off');
                }
                $user    = $this->loadUser($ids[0]);
                $matched = true;
            } elseif (!empty($settings['auto_create'])) {
                $user = $this->createUser($identity, (int) ($settings['auto_create_group'] ?? 2), $area, $provider);
                $created = true;
            } else {
                $this->fail('no_account', $provider);
            }
        }

        if (!$user->id || $user->block || ((string) $user->activation !== '' && (string) $user->activation !== '0')) {
            $this->fail('blocked', $provider, (int) $user->id);
        }
        if (!$settings['allow_privileged'] && $this->isAdminLevel($user)) {
            $this->fail('privileged', $provider, (int) $user->id);
        }
        $allowedGroups = array_map('intval', (array) ($settings['allowed_groups'] ?? []));
        if ($allowedGroups && !array_intersect($allowedGroups, array_map('intval', Access::getGroupsByUser((int) $user->id, true)))) {
            $this->fail('no_access', $provider, (int) $user->id, 'group');
        }
        if (!$user->authorise($area === 'administrator' ? 'core.login.admin' : 'core.login.site')) {
            $this->fail('no_access', $provider, (int) $user->id);
        }

        // Everything allowed: remember the identity (first sign-in by e-mail match) and sign the user in.
        if ($linked === null) {
            if (!$this->store->addIdentity((int) $user->id, $provider, $identity['sub'], $email)) {
                $this->fail('taken', $provider, (int) $user->id);
            }
            $linked = $this->store->findIdentity($provider, $identity['sub']);
        }
        if (!$this->performLogin($user, $area)) {
            $this->fail('login_denied', $provider, (int) $user->id);
        }
        if ($linked !== null) {
            $this->store->touch($linked['id']);
        }
        $this->store->log($created ? 'created' : ($matched ? 'linked_by_email' : 'login'), $provider, $area, (int) $user->id, $email, $this->ip());

        $app = $this->ctx->app();
        $app->getSession()->close();
        $app->redirect($area === 'administrator' ? Uri::root() . 'administrator/index.php' : ($return !== '' ? $return : Uri::root()));
        exit;
    }

    /** @return string[] lowercase domains */
    private function allowedDomains(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($raw)) ?: [] as $d) {
            $d = ltrim(trim($d), '@');
            if ($d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) {
                $out[] = $d;
            }
        }

        return array_values(array_unique($out));
    }

    private function userFactory(): UserFactoryInterface
    {
        return \Joomla\CMS\Factory::getContainer()->get(UserFactoryInterface::class);
    }

    private function loadUser(int $id): User
    {
        return $this->userFactory()->loadUserById($id);
    }

    /** True for users who can run or install code or manage users (super users, administrators). */
    public static function isAdminLevel(User $user): bool
    {
        foreach (self::ADMIN_ACTIONS as [$action, $asset]) {
            if ($asset === null ? $user->authorise($action) : $user->authorise($action, $asset)) {
                return true;
            }
        }

        return false;
    }

    /** True when a group (not a user) holds one of the privileged actions. */
    public static function isAdminGroup(int $groupId): bool
    {
        foreach (self::ADMIN_ACTIONS as [$action, $asset]) {
            if (Access::checkGroup($groupId, $action, $asset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates the Joomla account of a verified provider identity (only when the administrator turned auto-create on).
     *
     * @param array{sub:string,email:string,verified:bool,name:string} $identity
     */
    private function createUser(array $identity, int $groupId, string $area, string $provider): User
    {
        $db     = $this->ctx->db();
        $exists = $groupId > 1 ? (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__usergroups'))->where($db->quoteName('id') . ' = :g')->bind(':g', $groupId, \Joomla\Database\ParameterType::INTEGER)
        )->loadResult() : 0;
        // Never into a privileged group, and only into a group that may use the application the visitor came from
        // (otherwise the new account could not sign in and would stay behind).
        if (!$exists || self::isAdminGroup($groupId) || !Access::checkGroup($groupId, $area === 'administrator' ? 'core.login.admin' : 'core.login.site')) {
            $this->fail('create_group', $provider);
        }

        $local = (string) strstr($identity['email'], '@', true);
        $base  = mb_substr(preg_replace('/[^a-z0-9._-]+/i', '_', $local) ?: 'user', 0, 60);
        $name  = $base;
        for ($i = 1; UserHelper::getUserId($name) > 0; $i++) {
            $name = $base . '_' . $i;
            if ($i > 50) {
                $this->fail('create_failed', $provider);
            }
        }

        $user                 = $this->userFactory()->loadUserById(0);
        $user->name           = $identity['name'] !== '' ? $identity['name'] : $local;
        $user->username       = $name;
        $user->email          = $identity['email'];
        $user->password       = UserHelper::hashPassword(bin2hex(random_bytes(24))); // nobody knows it: the provider is the credential
        $user->groups         = [$groupId];
        $user->block          = 0;
        $user->activation     = '';
        $user->registerDate   = \Joomla\CMS\Factory::getDate()->toSql();
        try {
            $saved = $user->save();
        } catch (\Throwable $e) {
            $this->fail('create_failed', $provider, 0, mb_substr($e->getMessage(), 0, 120));
        }
        if (!$saved) {
            $this->fail('create_failed', $provider);
        }

        return $user;
    }

    /**
     * Signs the user in through the standard user-plugin chain (the events CMSApplication::login() fires). Joomla's own
     * multi-factor step is NOT skipped: a user with MFA still has to pass it after the provider.
     */
    private function performLogin(User $user, string $area): bool
    {
        $app        = $this->ctx->app();
        $dispatcher = $app->getDispatcher();
        PluginHelper::importPlugin('user', null, true, $dispatcher);

        $response                 = new AuthenticationResponse();
        $response->type           = 'UXStudio-ThirdParty';
        $response->status         = Authentication::STATUS_SUCCESS;
        $response->username       = $user->username;
        $response->email          = $user->email;
        $response->fullname       = $user->name;
        $response->password_clear = '';
        $options                  = ['action' => $area === 'administrator' ? 'core.login.admin' : 'core.login.site', 'remember' => false, 'silent' => true];

        $event = new LoginEvent('onUserLogin', ['subject' => (array) $response, 'options' => $options]);
        $dispatcher->dispatch('onUserLogin', $event);
        if (\in_array(false, $event['result'] ?? [], true)) {
            return false;
        }

        $current = $app->getIdentity();
        if (!$current || (int) $current->id !== (int) $user->id) {
            return false;
        }
        $options['user']         = $current;
        $options['responseType'] = $response->type;
        $dispatcher->dispatch('onUserAfterLogin', new AfterLoginEvent('onUserAfterLogin', ['options' => $options, 'subject' => (array) $response]));

        return true;
    }
}

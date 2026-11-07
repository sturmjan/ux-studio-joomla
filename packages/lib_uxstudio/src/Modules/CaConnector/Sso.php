<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Event\User\AfterLoginEvent;
use Joomla\CMS\Event\User\LoginEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Single sign-on from the Central App into the Joomla administration.
 *
 *  1. CA calls the signed node route sso/issue: we map the operator to a Joomla user and store a one-time token (60 s).
 *  2. The operator's browser opens {site}/administrator/index.php?uxstudio_sso=TOKEN: redeem() validates and consumes
 *     the token, logs the user in through the standard "user" plugin chain and redirects into /administrator/.
 *
 * Policy (mirrors the WordPress module): an existing user is matched by e-mail and keeps their own permissions; an
 * unknown operator is provisioned only into Manager / Editor, never into an administrative group; logging in as an
 * administrator-level user (installs code) is opt-in per site (setting sso_allow_admin).
 */
final class Sso
{
    public const QUERY = 'uxstudio_sso';

    /** CA role name => default group id (used when no group with that title exists). */
    private const GROUPS = ['Super Users' => 8, 'Administrator' => 7, 'Manager' => 6, 'Editor' => 4];

    private const PROVISIONABLE = ['Manager', 'Editor'];

    public function __construct(private ModuleContext $ctx, private Store $store)
    {
    }

    private function db(): DatabaseInterface
    {
        return $this->ctx->db();
    }

    /* ------------------------------------------------------------------ issue */

    /**
     * @param array<string,mixed> $p body of sso/issue
     * @return array{0:int,1:array<string,mixed>} HTTP status and JSON body
     */
    public function issue(array $p): array
    {
        $email  = trim((string) ($p['operator_email'] ?? ''));
        $opId   = (int) ($p['operator_id'] ?? 0);
        $role   = trim((string) ($p['target_role'] ?? $p['target_wp_role'] ?? ''));
        $ua     = preg_match('/^[a-f0-9]{64}$/i', (string) ($p['ua_hash'] ?? '')) ? strtolower((string) $p['ua_hash']) : '';
        $ip     = mb_substr((string) ($p['ip'] ?? ''), 0, 45);
        $return = (string) ($p['return_to'] ?? '/administrator/');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $opId <= 0) {
            return [400, ['success' => false, 'error' => 'invalid_payload']];
        }
        if ($this->strategy() === 'disabled') {
            return [403, ['success' => false, 'error' => 'sso_disabled']];
        }
        if (!isset(self::GROUPS[$role])) {
            return [422, ['success' => false, 'error' => 'invalid_target_role']];
        }

        $limit = max(1, (int) $this->ctx->setting('sso_max_issue_per_hour', 30));
        if ((new RateLimiter($this->db()))->hit('ca-connector:sso-issue:' . strtolower($email), $limit, 3600) > 0) {
            return [429, ['success' => false, 'error' => 'rate_limited_operator']];
        }

        // Nothing is created here: an account is provisioned only when the link is really redeemed.
        $resolved = $this->resolveUser($email, false);
        if ($resolved['error'] !== '') {
            $this->store->log('sso_issue', false, $resolved['error'] . ' (' . $email . ')');

            return [$resolved['error'] === 'admin_sso_disabled' ? 403 : 422, ['success' => false, 'error' => $resolved['error']]];
        }

        $token = bin2hex(random_bytes(32));
        $ttl   = max(10, min(300, (int) $this->ctx->setting('sso_token_ttl', 60)));
        $exp   = $this->store->createSsoToken($token, [
            'operator_email' => $email,
            'operator_id'    => $opId,
            'central_role'   => (string) ($p['central_role'] ?? ''),
            'target_role'    => $role,
            'user_id'        => $resolved['user_id'],
            'ua_hash'        => $ua,
            'ip'             => $ip,
            'return_to'      => $this->safeReturn($return),
        ], $ttl);
        $this->store->log('sso_issue', true, $email . ' -> user #' . $resolved['user_id'] . ' (' . $resolved['source'] . ')', $resolved['user_id']);

        return [200, [
            'success'    => true,
            'token'      => $token,
            'expires_in' => max(1, (int) strtotime($exp . ' UTC') - time()),
            'login_url'  => rtrim(Uri::root(), '/') . '/administrator/index.php?' . self::QUERY . '=' . $token,
            'mapped_via' => $resolved['source'],
        ]];
    }

    public function revokeAll(): int
    {
        $n = $this->store->revokeAllSso();
        $this->store->log('sso_revoke_all', true, (string) $n);

        return $n;
    }

    /**
     * Operator -> Joomla user: by e-mail (or auto-provisioned, only with $create = true and strategy "auto").
     * Whoever the user is, the result is refused up front when the account could not sign in to the administration.
     *
     * @return array{user_id:int,source:string,error:string}
     */
    private function resolveUser(string $email, bool $create): array
    {
        $db = $this->db();
        $id = (int) $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__users'))
                ->where('LOWER(' . $db->quoteName('email') . ') = :e')->bind(':e', strtolower($email))->order($db->quoteName('id') . ' ASC'),
            0,
            1
        )->loadResult();

        if ($id > 0) {
            $user = $this->loadUser($id);
            if ($user->block) {
                return ['user_id' => 0, 'source' => 'email', 'error' => 'user_blocked'];
            }
            if ($this->isAdminLevel($user) && !$this->ctx->setting('sso_allow_admin', false)) {
                return ['user_id' => 0, 'source' => 'email', 'error' => 'admin_sso_disabled'];
            }

            if (!$user->authorise('core.login.admin')) {
                return ['user_id' => 0, 'source' => 'email', 'error' => 'no_admin_access'];
            }

            return ['user_id' => $id, 'source' => 'email', 'error' => ''];
        }

        if ($this->strategy() !== 'auto') {
            return ['user_id' => 0, 'source' => 'none', 'error' => 'user_not_found'];
        }
        $role    = $this->provisionRole();
        $groupId = $this->groupId($role);
        if ($groupId <= 0 || !Access::checkGroup($groupId, 'core.login.admin')) {
            return ['user_id' => 0, 'source' => 'none', 'error' => 'no_admin_access'];
        }
        if (!$create) {
            return ['user_id' => 0, 'source' => 'provision', 'error' => ''];
        }
        $created = $this->provision($email, $role, $groupId);

        return $created > 0
            ? ['user_id' => $created, 'source' => 'provisioned', 'error' => '']
            : ['user_id' => 0, 'source' => 'none', 'error' => 'provision_failed'];
    }

    private function strategy(): string
    {
        $s = (string) $this->ctx->setting('sso_mapping_strategy', 'auto');

        return \in_array($s, ['auto', 'strict', 'disabled'], true) ? $s : 'auto';
    }

    /** Group for operators created here: set by the site administrator, never an administrative group, whatever the CA asks for. */
    private function provisionRole(): string
    {
        $role = (string) $this->ctx->setting('sso_provision_role', 'Manager');

        return \in_array($role, self::PROVISIONABLE, true) ? $role : 'Manager';
    }

    /** Creates a Joomla user for an operator in the (non administrative) group. */
    private function provision(string $email, string $role, int $groupId): int
    {        $local = strstr($email, '@', true);
        $base  = mb_substr('central_' . preg_replace('/[^a-z0-9_-]+/i', '_', $local !== false ? $local : $email), 0, 60);
        $name  = $base;
        for ($i = 1; UserHelper::getUserId($name) > 0; $i++) {
            $name = $base . '_' . $i;
            if ($i > 50) {
                return 0;
            }
        }

        $user                = $this->userFactory()->loadUserById(0);
        $user->name          = $email;
        $user->username      = $name;
        $user->email         = $email;
        $user->password_clear = bin2hex(random_bytes(24));
        $user->password      = UserHelper::hashPassword($user->password_clear);
        $user->groups        = [$groupId];
        $user->block         = 0;
        $user->activation    = '';
        $user->registerDate  = \Joomla\CMS\Factory::getDate()->toSql();
        if (!$user->save()) {
            $this->store->log('sso_provision', false, $email);

            return 0;
        }
        $this->store->log('sso_provision', true, $email . ' as ' . $role, (int) $user->id);

        return (int) $user->id;
    }

    private function groupId(string $role): int
    {
        $db = $this->db();
        $id = (int) $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'))->where($db->quoteName('title') . ' = :t')->bind(':t', $role)
        )->loadResult();
        if ($id > 0) {
            return $id;
        }
        $default = self::GROUPS[$role];
        $exists  = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__usergroups'))->where($db->quoteName('id') . ' = :i')->bind(':i', $default, ParameterType::INTEGER)
        )->loadResult();

        return $exists ? $default : 0;
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
    private function isAdminLevel(User $user): bool
    {
        return $user->authorise('core.admin')
            || $user->authorise('core.admin', 'com_config')
            || $user->authorise('core.manage', 'com_installer')
            || $user->authorise('core.manage', 'com_users')
            || $user->authorise('core.manage', 'com_plugins')
            || $user->authorise('core.admin', 'com_plugins')
            || $user->authorise('core.manage', 'com_templates')
            || $user->authorise('core.edit', 'com_templates');
    }

    /** return_to is restricted to a path inside /administrator/ (no host, no scheme). */
    private function safeReturn(string $raw): string
    {
        $parts = parse_url($raw);
        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//') || !\is_array($parts) || isset($parts['host']) || isset($parts['scheme'])) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        if (!str_starts_with($path, '/administrator/')) {
            return '';
        }
        $rel = substr($path, \strlen('/administrator/'));
        if (!empty($parts['query'])) {
            $rel .= '?' . $parts['query'];
        }

        return $rel;
    }

    /* ------------------------------------------------------------------ redeem */

    /** Called early in the administrator request when ?uxstudio_sso= is present. Always ends with a redirect. */
    public function redeem(CMSApplicationInterface $app): void
    {
        $token = (string) ($_GET[self::QUERY] ?? '');
        $ip    = ClientIp::current();

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->fail($app, 'invalid_token_format');
        }
        if ($this->store->recentFailures('sso_redeem', $ip, 60) >= 5) {
            $this->fail($app, 'rate_limited');
        }
        $row = $this->store->findSsoToken($token);
        if ($row === null) {
            $this->fail($app, 'token_not_found');
        }
        $now = time();
        if ((int) strtotime($row['expires'] . ' UTC') < $now) {
            $this->fail($app, 'token_expired');
        }
        if (!empty($row['consumed']) || !empty($row['revoked'])) {
            $this->fail($app, !empty($row['revoked']) ? 'token_revoked' : 'token_used');
        }
        if ($this->ctx->setting('sso_require_ua_match', true) && $row['ua_hash'] !== '') {
            $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            if (!hash_equals((string) $row['ua_hash'], $ua)) {
                $this->fail($app, 'ua_mismatch');
            }
        }
        if ($this->ctx->setting('sso_require_ip_match', false) && $row['ip'] !== '' && $row['ip'] !== $ip) {
            $this->fail($app, 'ip_mismatch');
        }

        if ($this->strategy() === 'disabled') {
            $this->fail($app, 'sso_disabled');
        }
        $userId = (int) $row['user_id'];
        if ($userId === 0) {
            // Provisioned lazily: the account is created only now that the link is really used.
            $res = $this->resolveUser((string) $row['operator_email'], true);
            if ($res['error'] !== '') {
                $this->fail($app, $res['error']);
            }
            $userId = $res['user_id'];
        }
        $user = $this->loadUser($userId);
        if (!$user->id || $user->block) {
            $this->fail($app, 'user_unavailable');
        }
        // Re-check at redeem time: the user may have been promoted or the setting switched off since the token was issued.
        if ($this->isAdminLevel($user) && !$this->ctx->setting('sso_allow_admin', false)) {
            $this->fail($app, 'admin_sso_disabled');
        }
        if (!$user->authorise('core.login.admin')) {
            $this->fail($app, 'no_admin_access');
        }
        if (!$this->store->consumeSsoToken((int) $row['id'])) {
            $this->fail($app, 'token_used');
        }

        if (!$this->login($app, $user)) {
            $this->fail($app, 'login_failed');
        }
        $this->store->log('sso_redeem', true, $row['operator_email'] . ' -> ' . $user->username, (int) $user->id);

        $target = Uri::base() . ((string) $row['return_to'] !== '' ? $row['return_to'] : 'index.php');
        $app->getSession()->close();
        $app->redirect($target);
    }

    /** Logs the user in through the standard user-plugin chain (the same events CMSApplication::login() fires). */
    private function login(CMSApplicationInterface $app, User $user): bool
    {
        $dispatcher = $app->getDispatcher();
        PluginHelper::importPlugin('user', null, true, $dispatcher);

        $response                = new AuthenticationResponse();
        $response->type          = 'UXStudio';
        $response->status        = Authentication::STATUS_SUCCESS;
        $response->username      = $user->username;
        $response->email         = $user->email;
        $response->fullname      = $user->name;
        $response->password_clear = '';
        $options = ['action' => 'core.login.admin', 'remember' => false, 'silent' => true];

        $event = new LoginEvent('onUserLogin', ['subject' => (array) $response, 'options' => $options]);
        $dispatcher->dispatch('onUserLogin', $event);
        $results = $event['result'] ?? [];
        if (\in_array(false, $results, true)) {
            return false;
        }

        $identity = $app->getIdentity();
        if (!$identity || (int) $identity->id !== (int) $user->id) {
            return false;
        }
        $options['user']         = $identity;
        $options['responseType'] = $response->type;
        $dispatcher->dispatch('onUserAfterLogin', new AfterLoginEvent('onUserAfterLogin', ['options' => $options, 'subject' => (array) $response]));

        // The Central App is the trusted second factor of this login: skip the Joomla MFA captive page for this session.
        $app->getSession()->set('com_users.mfa_checked', 1);

        return true;
    }

    private function fail(CMSApplicationInterface $app, string $reason): never
    {
        if ($reason !== 'rate_limited') { // throttled attempts are not logged, anonymous callers must not grow the table
            $this->store->log('sso_redeem', false, $reason);
        }
        $app->enqueueMessage(Text::sprintf('UXS_CA_CONNECTOR_SSO_FAILED', $reason), 'error');
        $app->getSession()->close();
        $app->redirect(Uri::base() . 'index.php');
        exit;
    }
}

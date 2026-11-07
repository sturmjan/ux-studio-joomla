<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Security optimization: response headers + CSP, hidden version info, IP firewall, login protection and a hardening report.
 *
 * Rescue switches (when you lock yourself out): define UXSTUDIO_DISABLE_LOGIN_PROTECTION in administrator/defines.php to
 * switch off the lock-out, Turnstile and secret-URL gate; or disable the module in the database
 * (UPDATE #__uxstudio_modules SET enabled = 0 WHERE id = 'security-optimization'). The firewall never blocks loopback,
 * allowlisted addresses or a logged-in super user.
 */
final class Module extends BaseModule
{
    private const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const GATE_FLAG        = 'uxs.admin_gate';

    /** @var array<string,mixed>|null */
    private ?array $settings = null;
    private ?string $ip = null;
    private string $nonce = '';
    private ?IpBans $bans = null;
    private ?LoginGuard $guard = null;

    /* ------------------------------------------------------------------ boot */

    public function boot(ModuleContext $ctx): void
    {
        $app = $ctx->app();
        if ($app->isClient('cli')) {
            return;
        }
        $s = $this->s();

        if (!empty($s['hide_generator']) && !headers_sent()) {
            header_remove('X-Powered-By');
        }

        $ctx->on('onAfterRoute', [$this, 'afterRoute'], Priority::MAX);
        // The Web Services application (/api/) fires its own routing events instead of onAfterRoute.
        $ctx->on('onAfterApiRoute', [$this, 'afterApiRoute'], Priority::MAX);
        $ctx->on('onUserLogin', [$this, 'loginGuardCheck'], Priority::MAX);
        $ctx->on('onBeforeCompileHead', [$this, 'beforeHead'], Priority::MAX);
        $ctx->on('onAfterRender', [$this, 'afterRender'], Priority::MIN);
        $ctx->on('onUserLoginFailure', [$this, 'loginFailed']);
        $ctx->on('onUserAfterLogin', [$this, 'loginSucceeded']);
    }

    /** All settings of the module, read once per request. @return array<string,mixed> */
    private function s(): array
    {
        return $this->settings ??= $this->ctx()->settings();
    }

    private function bans(): IpBans
    {
        return $this->bans ??= new IpBans($this->ctx()->db());
    }

    private function guard(): LoginGuard
    {
        return $this->guard ??= new LoginGuard($this->ctx()->db());
    }

    /** Client IP according to the configured proxy mode (see ClientIp). */
    private function clientIp(): string
    {
        if ($this->ip === null) {
            // Mode and trusted proxies are core settings (Core\ClientIp).
            $this->ip = ClientIp::current();
        }

        return $this->ip;
    }

    private function loginProtectionOn(): bool
    {
        return !\defined('UXSTUDIO_DISABLE_LOGIN_PROTECTION');
    }

    /* ------------------------------------------------------------- listeners */

    /** Only administrators with core.admin may touch the firewall, login protection and CSP settings or read the login log. */
    public function acl(): string
    {
        return 'core.admin';
    }

    public function afterApiRoute(Event $event): void
    {
        $this->baseHeaders();
        $this->firewall();
    }

    /**
     * onUserLogin: the lock-out is also enforced at the authentication level, so it does not depend on the shape of the
     * login request (task casing, module forms, custom components). Remember-me cookie logins are not password guesses.
     */
    public function loginGuardCheck(Event $event): void
    {
        $s = $this->s();
        if (!$this->loginProtectionOn() || empty($s['limit_login_attempts']) || !method_exists($event, 'addResult')) {
            return;
        }
        $subject = $this->eventSubject($event);
        if (($subject['type'] ?? '') === 'Cookie') {
            return;
        }
        $ip = $this->clientIp();
        if ($ip === '' || $this->loginExempt($ip)) {
            return;
        }
        $wait = $this->guard()->lockedFor($ip);
        if ($wait > 0) {
            $this->guard()->log('blocked', $ip, (string) ($subject['username'] ?? ''));
            $event->addResult(false);
        }
    }

    /**
     * Allowlisted addresses are never locked out. Loopback / private addresses ARE counted and locked out temporarily (a wrongly
     * configured IP mode must not silently switch brute-force protection off); they are just never banned (see loginFailed) and
     * the overview warns about them (local_ip).
     */
    private function loginExempt(string $ip): bool
    {
        return ClientIp::matches($ip, ClientIp::parseList((string) ($this->s()['ip_firewall_allowlist'] ?? '')));
    }

    private function trustedList(): array
    {
        return ClientIp::trusted();
    }

    /** Basic hardening headers that must also be on redirects and error pages (the full set follows in onAfterRender). */
    private function baseHeaders(): void
    {
        $s = $this->s();
        if (empty($s['security_headers'])) {
            return;
        }
        $app = $this->ctx()->app();
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('X-Frame-Options', 'SAMEORIGIN', true);
        $app->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin', true);
        if (!empty($s['enable_hsts']) && $app->isSsl()) {
            $app->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', true);
        }
    }

    /**
     * The firewall and the login gate (secret administrator URL) for endpoints that answer BEFORE onAfterRoute
     * (the third-party login start and callback). Ends the request itself when it must be refused.
     */
    public function guardEarlyEndpoint(): void
    {
        $this->firewall();
        if ($this->loginProtectionOn()) {
            $this->loginGate();
        }
    }

    public function afterRoute(Event $event): void
    {
        $app = $this->ctx()->app();
        $this->baseHeaders();
        $this->firewall();
        if ($this->loginProtectionOn()) {
            $this->loginGate();
        }
        $this->enumerationGuard();
        if ($app->isClient('site')) {
            $this->prepareCsp();
        }
    }

    /** onBeforeCompileHead: generator tag, CSP nonce on the document, Turnstile script on the login page. */
    public function beforeHead(Event $event): void
    {
        $app = $this->ctx()->app();
        $doc = $app->getDocument();
        if (!$doc instanceof HtmlDocument) {
            return;
        }
        if (!empty($this->s()['hide_generator'])) {
            $doc->setGenerator('');
        }
        if ($this->nonce !== '') {
            $doc->cspNonce = $this->nonce;
        }
        if ($this->turnstileOnThisPage()) {
            $doc->getWebAssetManager()->registerAndUseScript('uxs.secopt.turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], ['async' => true, 'defer' => true]);
        }
    }

    /** onAfterRender (last): response headers, CSP, Turnstile widget markup. */
    public function afterRender(Event $event): void
    {
        $app = $this->ctx()->app();
        $s   = $this->s();

        if (!empty($s['security_headers'])) {
            $app->setHeader('X-Content-Type-Options', 'nosniff', true);
            $app->setHeader('X-Frame-Options', 'SAMEORIGIN', true);
            $app->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin', true);
        }
        if (!empty($s['enable_hsts']) && $app->isSsl()) {
            $app->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', true);
        }
        if (!empty($s['enable_permissions_policy'])) {
            $app->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()', true);
        }

        // CSP is for the public site only, never for the administrator (it would break extensions' admin UIs).
        $mode = (string) ($s['csp_mode'] ?? 'off');
        if ($mode !== 'off' && $app->isClient('site') && $app->getDocument() instanceof HtmlDocument) {
            $policy = CspPolicy::build($s, $this->nonce, $this->cspReportUrl());
            $app->setHeader($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $policy, true);
        }

        if ($this->turnstileOnThisPage()) {
            $body = $app->getBody();
            if (\is_string($body) && $body !== '') {
                $widget = '<div class="form-group"><div class="cf-turnstile" data-sitekey="' . htmlspecialchars((string) $s['turnstile_site_key'], ENT_QUOTES, 'UTF-8') . '" data-theme="auto"></div></div>';
                $new    = preg_replace('/(<div class="form-group">\s*<button type="submit" id="btn-login-submit")/', $widget . '$1', $body, 1, $count);
                if (!$count) {
                    // Other administrator template / mod_login override: put the widget at the end of the login form.
                    $new = preg_replace('#(<form\b[^>]*\bid="form-login"[^>]*>.*?)(</form>)#s', '$1' . str_replace('$', '\\$', $widget) . '$2', $body, 1, $count);
                }
                if (\is_string($new) && $count) {
                    $app->setBody($new);
                }
                $this->widgetFlag($count > 0);
            }
        }
    }

    /** onUserLoginFailure: counts the failure and locks the address out when the limit is reached. */
    public function loginFailed(Event $event): void
    {
        $s = $this->s();
        if (!$this->loginProtectionOn() || empty($s['limit_login_attempts'])) {
            return;
        }
        $subject  = $this->eventSubject($event);
        $username = (string) ($subject['username'] ?? '');
        $ip       = $this->clientIp();
        if ($ip === '' || $this->loginExempt($ip) || $this->guard()->lockedFor($ip) > 0) {
            return;
        }
        $lock = $this->guard()->fail($ip, $username, [
            'max'            => (int) $s['max_attempts'],
            'window_minutes' => (int) $s['lockout_time'],
            'minutes'        => (int) $s['lockout_time'],
            'progressive'    => !empty($s['lockout_progressive']),
            'multiplier'     => max(1, (int) $s['lockout_multiplier']),
            'max_hours'      => max(1, (int) $s['lockout_max_hours']),
        ]);
        if ($lock === null) {
            return;
        }

        $autoAfter = (int) ($s['lockout_auto_ban_after'] ?? 0);
        if ($autoAfter > 0 && $lock['strikes'] >= $autoAfter && ClientIp::isProtected($ip, $this->trustedList())) {
            $this->guard()->log('would_ban', $ip, $username);
        }
        if ($autoAfter > 0 && $lock['strikes'] >= $autoAfter && !ClientIp::isProtected($ip, $this->trustedList())) {
            // Loopback, private and proxy addresses are never banned automatically (only locked out and reported).
            try {
                $this->bans()->save([
                    'value'      => $ip,
                    'label'      => 'Auto: login brute force',
                    'note'       => $lock['strikes'] . ' lock-outs within 24 hours',
                    'origin'     => 'auto',
                    'expires_at' => time() + max(1, (int) $s['lockout_auto_ban_hours']) * 3600,
                ]);
            } catch (\Throwable $e) {
                Log::add('Auto ban failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
            }
        }
        if (!empty($s['notify_admin'])) {
            $this->notify(
                Text::sprintf('UXS_SECURITY_OPTIMIZATION_MAIL_LOCKOUT_SUBJECT', $this->siteName()),
                Text::sprintf('UXS_SECURITY_OPTIMIZATION_MAIL_LOCKOUT_BODY', $ip, $username, $lock['minutes'], $lock['strikes'])
            );
        }
    }

    public function loginSucceeded(Event $event): void
    {
        if (!$this->loginProtectionOn()) {
            return;
        }
        $ip = $this->clientIp();
        if ($ip === '') {
            return;
        }
        $subject  = $this->eventSubject($event);
        $username = (string) ($subject['username'] ?? ($subject['user']->username ?? ''));
        $this->guard()->succeed($ip, $username);
    }

    /** Joomla 5/6 typed events carry the payload in the "subject" argument; older style in position 0. @return array<string,mixed> */
    private function eventSubject(Event $event): array
    {
        foreach (['subject', '0'] as $name) {
            $arg = $event->hasArgument($name) ? $event->getArgument($name) : null;
            if (\is_array($arg)) {
                return $arg;
            }
            if (\is_object($arg)) {
                return (array) $arg;
            }
        }

        return [];
    }

    /* ---------------------------------------------------------------- firewall */

    private function firewall(): void
    {
        $s = $this->s();
        if (empty($s['ip_firewall_enabled'])) {
            return;
        }
        $ip = $this->clientIp();
        if ($ip === '' || ClientIp::isLoopback($ip) || ClientIp::matches($ip, ClientIp::parseList((string) ($s['ip_firewall_allowlist'] ?? '')))) {
            return;
        }
        try {
            $ban = $this->bans()->match($ip);
        } catch (\Throwable $e) {
            // Tables missing (before the first migration): fail open.
            return;
        }
        if ($ban === null) {
            return;
        }
        $identity = $this->ctx()->app()->getIdentity();
        if ($identity && !$identity->guest && $identity->authorise('core.admin')) {
            return;
        }
        $this->bans()->hit((int) $ban['id']);
        $message = trim((string) ($s['ip_firewall_message'] ?? ''));
        $this->deny(403, $message !== '' ? $message : 'Access denied.');
    }

    /* -------------------------------------------------------------- login gate */

    private function isLoginPost(): bool
    {
        $app   = $this->ctx()->app();
        $input = $app->getInput();
        if (strtoupper($input->getMethod()) !== 'POST') {
            return false;
        }
        // Joomla lower-cases the task (BaseController) and splits "controller.task", so compare the normalised form.
        $option = strtolower($input->getCmd('option'));
        $task   = strtolower($input->getCmd('task'));
        $action = str_contains($task, '.') ? substr($task, (int) strrpos($task, '.') + 1) : $task;

        return ($app->isClient('administrator') && $option === 'com_login' && $action === 'login')
            || ($app->isClient('site') && $option === 'com_users' && $action === 'login');
    }

    /**
     * Single sign-on from the Central App: the gate steps aside ONLY when the ca-connector module is enabled (it redeems the
     * token in onAfterInitialise and always redirects) and the parameter has the shape of a token. Otherwise the parameter
     * is ignored, exactly as if it was not there.
     */
    private function ssoRedeem(): bool
    {
        $token = $this->ctx()->app()->getInput()->get('uxstudio_sso', '', 'raw');
        if (!\is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        try {
            return $this->ctx()->kernel()->registry()->isEnabled('ca-connector');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function secretKey(): string
    {
        $s   = $this->s();
        $key = (string) ($s['admin_secret_key'] ?? '');

        // A missing / malformed key switches the gate OFF (fail open) instead of locking everybody out.
        return !empty($s['admin_secret_enabled']) && preg_match('/^[A-Za-z0-9_]{4,40}$/', $key) ? $key : '';
    }

    private function turnstileActive(): bool
    {
        $s = $this->s();

        return $this->loginProtectionOn() && !empty($s['turnstile_enabled']) && trim((string) ($s['turnstile_site_key'] ?? '')) !== '' && (string) ($s['turnstile_secret_key'] ?? '') !== '';
    }

    private function turnstileOnThisPage(): bool
    {
        $app = $this->ctx()->app();

        return $app->isClient('administrator') && $this->turnstileActive() && $app->getIdentity()->guest
            && $app->getInput()->getCmd('option') === 'com_login' && strtoupper($app->getInput()->getMethod()) === 'GET';
    }

    private function loginGate(): void
    {
        $ctx   = $this->ctx();
        $app   = $ctx->app();
        $s     = $this->s();
        $input = $app->getInput();
        $ip    = $this->clientIp();

        // Secret administrator URL: guests reach the login only through /administrator/?<secret>.
        $secret = $this->secretKey();
        if ($secret !== '' && $app->isClient('administrator') && $app->getIdentity()->guest && !$this->ssoRedeem()) {
            $session = $app->getSession();
            if (isset($_GET[$secret])) {
                $session->set(self::GATE_FLAG, 1);
            } elseif (!$session->get(self::GATE_FLAG)) {
                // One log row per address and 10 minutes: scanners must not be able to fill the table.
                try {
                    if ((new RateLimiter($ctx->db()))->hit('secopt:secret:' . $ip, 1, 600) === 0) {
                        $this->guard()->log('secret', $ip);
                    }
                } catch (\Throwable $e) {
                }
                if (($s['admin_secret_behavior'] ?? '404') === 'home') {
                    $app->redirect(Uri::root());
                }
                $this->deny(404, 'Not Found');
            }
        }

        if (!$this->isLoginPost() || $ip === '') {
            return;
        }

        if (!empty($s['limit_login_attempts']) && !$this->loginExempt($ip)) {
            $wait = $this->guard()->lockedFor($ip);
            if ($wait > 0) {
                $this->guard()->log('blocked', $ip, (string) $input->post->getUsername('username'));
                $app->enqueueMessage(Text::sprintf('UXS_SECURITY_OPTIMIZATION_LOCKED', max(1, (int) ceil($wait / 60))), 'error');
                $app->redirect($app->isClient('administrator') ? Uri::base() : Uri::root());
            }
        }

        if ($app->isClient('administrator') && $this->turnstileActive() && $this->widgetRendered() && !$this->turnstilePasses($ip)) {
            $this->guard()->log('captcha', $ip, (string) $input->post->getUsername('username'));
            $app->enqueueMessage(Text::_('UXS_SECURITY_OPTIMIZATION_CAPTCHA_FAILED'), 'error');
            $app->redirect(Uri::base());
        }
    }

    private function widgetFlagFile(): string
    {
        return JPATH_CACHE . '/uxs-secopt-turnstile-widget.flag';
    }

    /** Remembers whether the last login page really got the widget (a broken template must not lock everybody out). */
    private function widgetFlag(bool $ok): void
    {
        $file = $this->widgetFlagFile();
        $want = $ok ? 'ok' : 'missing';
        if (@file_get_contents($file) !== $want) {
            @file_put_contents($file, $want, LOCK_EX);
            if (!$ok) {
                Log::add('Turnstile widget could not be inserted into the administrator login form; the check is not enforced.', Log::WARNING, 'uxstudio');
            }
        }
    }

    /** False only when the login page was rendered without the widget: then the token cannot be required. */
    private function widgetRendered(): bool
    {
        return @file_get_contents($this->widgetFlagFile()) !== 'missing';
    }

    /** Verifies the Turnstile token of the login POST. Unreachable Cloudflare = fail open (logged), a refused token = blocked. */
    private function turnstilePasses(string $ip): bool
    {
        $token = (string) $this->ctx()->app()->getInput()->post->getString('cf-turnstile-response', '');
        if ($token === '') {
            return false;
        }
        try {
            $res  = $this->ctx()->http()->request('POST', self::TURNSTILE_VERIFY, [
                'body'    => ['secret' => (string) $this->s()['turnstile_secret_key'], 'response' => $token, 'remoteip' => $ip],
                'timeout' => 6,
            ]);
            $json = json_decode($res['body'], true);

            return \is_array($json) && !empty($json['success']);
        } catch (\Throwable $e) {
            Log::add('Turnstile verification unavailable: ' . $e->getMessage(), Log::WARNING, 'uxstudio');

            return true;
        }
    }

    /* ------------------------------------------------------------ enumeration */

    /** Guests cannot walk through authors / user ids with the usual query parameters. */
    private function enumerationGuard(): void
    {
        $app = $this->ctx()->app();
        if (empty($this->s()['block_user_enumeration']) || !$app->isClient('site') || !$app->getIdentity()->guest) {
            return;
        }
        $input  = $app->getInput();
        $params = $input->get->getArray();
        $filter = isset($params['filter']) && \is_array($params['filter']) ? $params['filter'] : [];
        foreach (['author', 'author_id', 'created_by', 'filter_author', 'filter_author_id'] as $key) {
            if (isset($params[$key]) || isset($filter[$key])) {
                $this->deny(404, 'Not Found');
            }
        }
        if ($input->getCmd('option') === 'com_users' && \in_array($input->getCmd('view'), ['profile', 'users'], true) && $input->getInt('user_id') > 0) {
            $this->deny(404, 'Not Found');
        }
    }

    /* -------------------------------------------------------------------- CSP */

    private function prepareCsp(): void
    {
        $s = $this->s();
        if (($s['csp_mode'] ?? 'off') === 'off' || ($s['csp_level'] ?? 'basic') !== 'strict') {
            return;
        }
        $app   = $this->ctx()->app();
        $nonce = (string) $app->get('csp_nonce', '');
        if ($nonce === '') {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $app->set('csp_nonce', $nonce);
        }
        $this->nonce = $nonce;
    }

    private function cspReportUrl(): string
    {
        return Uri::root() . 'index.php?option=com_uxstudio&task=public.dispatch&format=json&route=security-optimization/csp-report';
    }

    /* ----------------------------------------------------------------- output */

    private function deny(int $status, string $message): never
    {
        $app = $this->ctx()->app();
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
            if (!empty($this->s()['security_headers'])) {
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: SAMEORIGIN');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                if (!empty($this->s()['enable_hsts']) && $app->isSsl()) {
                    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
                }
            }
        }
        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . $status . '</title><body style="font-family:sans-serif;padding:2rem"><h1>'
            . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body>';
        $app->close();
        exit;
    }

    private function siteName(): string
    {
        return (string) $this->ctx()->app()->get('sitename', 'Joomla');
    }

    /** E-mail to the configured address (default: the site mail address). Throttled to one per 5 minutes; never throws. */
    private function notify(string $subject, string $body): void
    {
        try {
            if ((new RateLimiter($this->ctx()->db()))->hit('security-optimization:notify', 1, 300) > 0) {
                return;
            }
            $to = trim((string) ($this->s()['lockout_notify_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $to = (string) $this->ctx()->app()->get('mailfrom', '');
            }
            if ($to === '') {
                return;
            }
            $mailer = \Ux1\UxStudio\Core\Mail::create();
            $mailer->addRecipient($to);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $mailer->isHtml(false);
            $mailer->Send();
        } catch (\Throwable $e) {
            Log::add('Security notification not sent: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    /* --------------------------------------------------------------- settings */

    public function settingsSchema(): array
    {
        $p = 'UXS_SECURITY_OPTIMIZATION_';

        return [
            // Firewall.
            ['key' => 'ip_firewall_enabled', 'section' => $p . 'SEC_FIREWALL', 'type' => 'toggle', 'label' => $p . 'F_FIREWALL', 'help' => $p . 'F_FIREWALL_HELP', 'default' => true],
            ['key' => 'ip_firewall_allowlist', 'section' => $p . 'SEC_FIREWALL', 'type' => 'textarea', 'label' => $p . 'F_ALLOWLIST', 'help' => $p . 'F_ALLOWLIST_HELP', 'default' => '', 'maxlength' => 4000],
            ['key' => 'ip_firewall_message', 'section' => $p . 'SEC_FIREWALL', 'type' => 'text', 'label' => $p . 'F_MESSAGE', 'help' => $p . 'F_MESSAGE_HELP', 'default' => '', 'maxlength' => 300],

            // Headers + information leaks.
            ['key' => 'security_headers', 'section' => $p . 'SEC_HEADERS', 'type' => 'toggle', 'label' => $p . 'F_HEADERS', 'help' => $p . 'F_HEADERS_HELP', 'default' => true],
            ['key' => 'enable_hsts', 'section' => $p . 'SEC_HEADERS', 'type' => 'toggle', 'label' => $p . 'F_HSTS', 'help' => $p . 'F_HSTS_HELP', 'default' => false],
            ['key' => 'enable_permissions_policy', 'section' => $p . 'SEC_HEADERS', 'type' => 'toggle', 'label' => $p . 'F_PERMISSIONS', 'help' => $p . 'F_PERMISSIONS_HELP', 'default' => false],
            ['key' => 'hide_generator', 'section' => $p . 'SEC_HEADERS', 'type' => 'toggle', 'label' => $p . 'F_GENERATOR', 'help' => $p . 'F_GENERATOR_HELP', 'default' => true],
            ['key' => 'block_user_enumeration', 'section' => $p . 'SEC_HEADERS', 'type' => 'toggle', 'label' => $p . 'F_ENUM', 'help' => $p . 'F_ENUM_HELP', 'default' => false],

            // CSP.
            ['key' => 'csp_mode', 'section' => $p . 'SEC_CSP', 'type' => 'select', 'label' => $p . 'F_CSP_MODE', 'help' => $p . 'F_CSP_MODE_HELP', 'default' => 'off',
             'options' => ['off' => $p . 'CSP_OFF', 'report-only' => $p . 'CSP_REPORT', 'enforce' => $p . 'CSP_ENFORCE']],
            ['key' => 'csp_level', 'section' => $p . 'SEC_CSP', 'type' => 'select', 'label' => $p . 'F_CSP_LEVEL', 'help' => $p . 'F_CSP_LEVEL_HELP', 'default' => 'basic',
             'options' => ['basic' => $p . 'CSP_BASIC', 'strict' => $p . 'CSP_STRICT'], 'show_if' => ['key' => 'csp_mode', 'in' => ['report-only', 'enforce']]],
            ['key' => 'csp_presets', 'section' => $p . 'SEC_CSP', 'type' => 'multiselect', 'label' => $p . 'F_CSP_PRESETS', 'help' => $p . 'F_CSP_PRESETS_HELP', 'default' => [],
             'options' => CspPolicy::presetLabels(), 'show_if' => ['key' => 'csp_mode', 'in' => ['report-only', 'enforce']]],
            ['key' => 'csp_custom_allowlist', 'section' => $p . 'SEC_CSP', 'type' => 'textarea', 'label' => $p . 'F_CSP_ALLOW', 'help' => $p . 'F_CSP_ALLOW_HELP', 'default' => '', 'maxlength' => 4000,
             'show_if' => ['key' => 'csp_mode', 'in' => ['report-only', 'enforce']]],
            ['key' => 'csp_extra_directives', 'section' => $p . 'SEC_CSP', 'type' => 'textarea', 'label' => $p . 'F_CSP_EXTRA', 'help' => $p . 'F_CSP_EXTRA_HELP', 'default' => '', 'maxlength' => 4000,
             'show_if' => ['key' => 'csp_mode', 'in' => ['report-only', 'enforce']]],

            // Login.
            ['key' => 'limit_login_attempts', 'section' => $p . 'SEC_LOGIN', 'type' => 'toggle', 'label' => $p . 'F_LIMIT', 'help' => $p . 'F_LIMIT_HELP', 'default' => true],
            ['key' => 'max_attempts', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_MAX', 'default' => 5, 'min' => 1, 'max' => 100, 'show_if' => ['key' => 'limit_login_attempts', 'truthy' => true]],
            ['key' => 'lockout_time', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_LOCK_TIME', 'help' => $p . 'F_LOCK_TIME_HELP', 'default' => 15, 'min' => 1, 'max' => 1440, 'show_if' => ['key' => 'limit_login_attempts', 'truthy' => true]],
            ['key' => 'lockout_progressive', 'section' => $p . 'SEC_LOGIN', 'type' => 'toggle', 'label' => $p . 'F_PROGRESSIVE', 'help' => $p . 'F_PROGRESSIVE_HELP', 'default' => false, 'show_if' => ['key' => 'limit_login_attempts', 'truthy' => true]],
            ['key' => 'lockout_multiplier', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_MULTIPLIER', 'default' => 2, 'min' => 1, 'max' => 10, 'show_if' => ['key' => 'lockout_progressive', 'truthy' => true]],
            ['key' => 'lockout_max_hours', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_MAX_HOURS', 'default' => 24, 'min' => 1, 'max' => 168, 'show_if' => ['key' => 'lockout_progressive', 'truthy' => true]],
            ['key' => 'lockout_auto_ban_after', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_AUTOBAN_AFTER', 'help' => $p . 'F_AUTOBAN_AFTER_HELP', 'default' => 3, 'min' => 0, 'max' => 50, 'show_if' => ['key' => 'limit_login_attempts', 'truthy' => true]],
            ['key' => 'lockout_auto_ban_hours', 'section' => $p . 'SEC_LOGIN', 'type' => 'number', 'label' => $p . 'F_AUTOBAN_HOURS', 'default' => 24, 'min' => 1, 'max' => 8760, 'show_if' => ['key' => 'limit_login_attempts', 'truthy' => true]],
            ['key' => 'notify_admin', 'section' => $p . 'SEC_LOGIN', 'type' => 'toggle', 'label' => $p . 'F_NOTIFY', 'help' => $p . 'F_NOTIFY_HELP', 'default' => false],
            ['key' => 'lockout_notify_email', 'section' => $p . 'SEC_LOGIN', 'type' => 'text', 'label' => $p . 'F_NOTIFY_EMAIL', 'help' => $p . 'F_NOTIFY_EMAIL_HELP', 'default' => '', 'maxlength' => 190, 'show_if' => ['key' => 'notify_admin', 'truthy' => true]],
            ['key' => 'admin_secret_enabled', 'section' => $p . 'SEC_LOGIN', 'type' => 'toggle', 'label' => $p . 'F_SECRET', 'help' => $p . 'F_SECRET_HELP', 'default' => false],
            ['key' => 'admin_secret_key', 'section' => $p . 'SEC_LOGIN', 'type' => 'text', 'label' => $p . 'F_SECRET_KEY', 'help' => $p . 'F_SECRET_KEY_HELP', 'default' => '', 'maxlength' => 40, 'show_if' => ['key' => 'admin_secret_enabled', 'truthy' => true]],
            ['key' => 'admin_secret_behavior', 'section' => $p . 'SEC_LOGIN', 'type' => 'select', 'label' => $p . 'F_SECRET_BEHAVIOR', 'default' => '404', 'options' => ['404' => $p . 'SECRET_404', 'home' => $p . 'SECRET_HOME'],
             'show_if' => ['key' => 'admin_secret_enabled', 'truthy' => true]],
            ['key' => 'turnstile_enabled', 'section' => $p . 'SEC_LOGIN', 'type' => 'toggle', 'label' => $p . 'F_TURNSTILE', 'help' => $p . 'F_TURNSTILE_HELP', 'default' => false],
            ['key' => 'turnstile_site_key', 'section' => $p . 'SEC_LOGIN', 'type' => 'text', 'label' => $p . 'F_TURNSTILE_SITE', 'default' => '', 'maxlength' => 100, 'show_if' => ['key' => 'turnstile_enabled', 'truthy' => true]],
            ['key' => 'turnstile_secret_key', 'section' => $p . 'SEC_LOGIN', 'type' => 'password', 'label' => $p . 'F_TURNSTILE_SECRET', 'show_if' => ['key' => 'turnstile_enabled', 'truthy' => true]],
        ];
    }

    /* ----------------------------------------------------------------- tables */

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_secopt_ip_bans` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `type` VARCHAR(10) NOT NULL DEFAULT \'single\', `value` VARCHAR(190) NOT NULL, '
                . '`label` VARCHAR(190) NOT NULL DEFAULT \'\', `note` VARCHAR(1000) NOT NULL DEFAULT \'\', `origin` VARCHAR(10) NOT NULL DEFAULT \'local\', '
                . '`central_id` INT UNSIGNED NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1, `expires_at` DATETIME NULL, `hit_count` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`last_hit_at` DATETIME NULL, `created_at` DATETIME NOT NULL, `created_by` INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`id`), KEY `idx_origin_value` (`origin`, `value`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_secopt_ip_ranges` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `ban_id` INT UNSIGNED NOT NULL, `ip_version` TINYINT UNSIGNED NOT NULL, '
                . '`start_ip` VARBINARY(16) NOT NULL, `end_ip` VARBINARY(16) NOT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_match` (`ip_version`, `start_ip`, `end_ip`), KEY `idx_ban` (`ban_id`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_secopt_lockouts` ('
                . '`ip` VARCHAR(45) NOT NULL, `fails` INT UNSIGNED NOT NULL DEFAULT 0, `window_start` DATETIME NULL, `locked_until` DATETIME NULL, '
                . '`strikes` INT UNSIGNED NOT NULL DEFAULT 0, `last_strike` DATETIME NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`ip`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_secopt_login_log` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `kind` VARCHAR(16) NOT NULL, `ip` VARCHAR(45) NOT NULL DEFAULT \'\', '
                . '`username` VARCHAR(150) NOT NULL DEFAULT \'\', PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_kind` (`kind`, `created`)) ' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_secopt_csp` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `hash` CHAR(40) NOT NULL, `directive` VARCHAR(40) NOT NULL DEFAULT \'\', `blocked` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`hits` INT UNSIGNED NOT NULL DEFAULT 1, `first_seen` DATETIME NOT NULL, `last_seen` DATETIME NOT NULL, `sample` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_hash` (`hash`), KEY `idx_seen` (`last_seen`)) ' . $tail,
            ],
        ];
    }

    public function retention(): array
    {
        return [
            ['table' => '#__uxstudio_secopt_login_log', 'column' => 'created', 'days' => 30],
            ['table' => '#__uxstudio_secopt_csp', 'column' => 'last_seen', 'days' => 90],
        ];
    }

    public function tasks(): array
    {
        return ['housekeeping' => ['label' => 'UXS_SECURITY_OPTIMIZATION_JOB_HOUSEKEEPING', 'callable' => [$this, 'housekeeping'], 'interval' => 'PT6H']];
    }

    /** Scheduler job: forgets idle lock-out counters and removes bans that expired more than a week ago. */
    public function housekeeping(): string
    {
        $this->guard()->purgeIdle();
        $db  = $this->ctx()->db();
        $old = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $ids = array_map('intval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_secopt_ip_bans'))
                ->where($db->quoteName('expires_at') . ' IS NOT NULL')->where($db->quoteName('expires_at') . ' < :o')->bind(':o', $old)
        )->loadColumn() ?: []);
        foreach ($ids as $id) {
            $this->bans()->delete($id);
        }

        $this->bans()->refreshFlag();

        return 'expired bans removed: ' . \count($ids);
    }

    public function dashboardWidget(): ?array
    {
        try {
            $stats = $this->guard()->stats(24);
            $bans  = $this->bans()->activeCount();
        } catch (\Throwable $e) {
            return null;
        }
        $bad = $stats['lockout'] > 0;

        return [
            'title'  => Text::_('UXS_SECURITY_OPTIMIZATION_NAME'),
            'value'  => $stats['fail'] + $stats['lockout'],
            'text'   => Text::sprintf('UXS_SECURITY_OPTIMIZATION_WIDGET', $bans, $stats['lockout']),
            'status' => $bad ? 'warn' : 'ok',
        ];
    }

    /* -------------------------------------------------------------- admin API */

    public function routes(): array
    {
        $admin = 'core.admin';

        return [
            'GET overview'                 => [$this, 'overview'],
            'GET ip-bans'                  => [$this, 'listBans'],
            'POST ip-bans'                 => ['handler' => [$this, 'saveBan'], 'acl' => $admin],
            'POST ip-bans/{id}/toggle'     => ['handler' => [$this, 'toggleBan'], 'acl' => $admin],
            'POST ip-bans/{id}/delete'     => ['handler' => [$this, 'deleteBan'], 'acl' => $admin],
            'GET login-log'                => [$this, 'loginLog'],
            'POST login/unlock'            => ['handler' => [$this, 'unlock'], 'acl' => $admin],
            'GET csp'                      => [$this, 'csp'],
            'POST csp/clear'               => ['handler' => [$this, 'clearCsp'], 'acl' => $admin],
            'GET hardening'                => [$this, 'hardening'],
            'GET hardening/probe'          => [$this, 'probe'],
        ];
    }

    public function publicRoutes(): array
    {
        return [
            // Browsers post violation reports here (Content-Security-Policy report-uri). Rate limited, stores aggregates only.
            'POST csp-report' => ['handler' => [$this, 'cspReport'], 'auth' => 'none', 'limit' => [120, 60], 'max_body' => 8192],
        ];
    }

    /** @return array<string,mixed> */
    public function overview(ApiRequest $r): array
    {
        $s     = $this->s();
        $stats = $this->guard()->stats(24);
        $ip    = $this->clientIp();
        $csp   = $this->db()->setQuery($this->db()->createQuery()->select('COUNT(*)')->from($this->db()->quoteName('#__uxstudio_secopt_csp')))->loadResult();

        $secret = $this->secretKey();

        return [
            'client_ip'        => $ip,
            'remote_addr'      => ClientIp::normalize((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'ip_mode'          => ClientIp::mode(),
            // The visitor address resolved to a Cloudflare edge / trusted proxy: the proxy mode does not match the real setup.
            'edge_warning'     => $ip !== '' && ClientIp::isEdge($ip, $this->trustedList()),
            // The visitor address is loopback / private: the IP mode is probably wrong (a reverse proxy in front of the site).
            'local_ip'         => $ip !== '' && ClientIp::isLocal($ip),
            'firewall'         => !empty($s['ip_firewall_enabled']),
            'active_bans'      => $this->bans()->activeCount(),
            'login_stats'      => $stats,
            'active_locks'     => $this->guard()->activeLocks(),
            'csp_mode'         => $s['csp_mode'],
            'csp_level'        => $s['csp_level'],
            'csp_reports'      => (int) $csp,
            'csp_policy'       => CspPolicy::build($s, $this->nonce !== '' ? $this->nonce : 'NONCE', $this->cspReportUrl()),
            'headers'          => !empty($s['security_headers']),
            'hsts'             => !empty($s['enable_hsts']),
            'generator_hidden' => !empty($s['hide_generator']),
            'login_limit'      => !empty($s['limit_login_attempts']),
            'turnstile'        => $this->turnstileActive(),
            'secret_url'       => $secret !== '' ? Uri::base() . 'index.php?' . $secret : '',
            'secret_enabled'   => !empty($s['admin_secret_enabled']),
            'secret_valid'     => $secret !== '',
            'rescue_active'    => !$this->loginProtectionOn(),
        ];
    }

    private function db(): \Joomla\Database\DatabaseInterface
    {
        return $this->ctx()->db();
    }

    /** @return array<string,mixed> */
    public function listBans(ApiRequest $r): array
    {
        $origin = $r->string('origin');

        return $this->bans()->list(trim($r->string('search')), \in_array($origin, ['local', 'central'], true) ? $origin : '', $r->int('page', 1), $r->int('per_page', 20));
    }

    /** @return array<string,mixed> */
    public function saveBan(ApiRequest $r): array
    {
        $body = $r->body();
        $id   = $this->bans()->save([
            'value'      => $body['value'] ?? '',
            'label'      => $body['label'] ?? '',
            'note'       => $body['note'] ?? '',
            'expires_at' => $body['expires_at'] ?? null,
            'is_active'  => $body['is_active'] ?? true,
            'id'         => (int) ($body['id'] ?? 0),
        ], $this->clientIp(), (int) $r->user()->id);

        return ['id' => $id];
    }

    /** @return array<string,mixed> */
    public function toggleBan(ApiRequest $r): array
    {
        $this->bans()->setActive((int) $r->param('id'), $r->bool('active'));

        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    public function deleteBan(ApiRequest $r): array
    {
        return ['deleted' => $this->bans()->delete((int) $r->param('id'))];
    }

    /** @return array<string,mixed> */
    public function loginLog(ApiRequest $r): array
    {
        $kind = $r->string('kind');

        return [
            'items' => $this->guard()->recent($r->int('limit', 100), \in_array($kind, ['fail', 'lockout', 'blocked', 'captcha', 'success', 'secret'], true) ? $kind : ''),
            'locks' => $this->guard()->activeLocks(),
            'stats' => $this->guard()->stats(24),
        ];
    }

    /** @return array<string,mixed> */
    public function unlock(ApiRequest $r): array
    {
        $ip = ClientIp::normalize($r->string('ip'));
        if ($ip === '') {
            throw new ApiException('Invalid IP address.', 422, 'invalid_value');
        }
        $this->guard()->unlock($ip);

        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    public function csp(ApiRequest $r): array
    {
        $db   = $this->db();
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_secopt_csp'))->order($db->quoteName('hits') . ' DESC'), 0, 200)->loadAssocList() ?: [];

        return [
            'mode'       => $this->s()['csp_mode'],
            'policy'     => CspPolicy::build($this->s(), 'NONCE', $this->cspReportUrl()),
            'violations' => array_map(static fn (array $v): array => [
                'id' => (int) $v['id'], 'directive' => $v['directive'], 'blocked' => $v['blocked'], 'hits' => (int) $v['hits'],
                'first_seen' => $v['first_seen'], 'last_seen' => $v['last_seen'], 'sample' => $v['sample'],
            ], $rows),
        ];
    }

    /** @return array<string,mixed> */
    public function clearCsp(ApiRequest $r): array
    {
        $db = $this->db();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__uxstudio_secopt_csp'))->execute();

        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    public function hardening(ApiRequest $r): array
    {
        $h = new Hardening($this->db(), $this->ctx()->app());

        return ['checks' => $h->checks($this->s()), 'leaky_files' => $h->presentLeakyFiles(), 'htaccess' => $h->htaccessSnippet(), 'super_users' => $h->superUsers()];
    }

    /** @return array<string,mixed> */
    public function probe(ApiRequest $r): array
    {
        return ['files' => (new Hardening($this->db(), $this->ctx()->app()))->probe()];
    }

    /**
     * Public: CSP violation report from a browser. Stores one aggregated row per (directive, blocked host); the table is
     * capped, so a hostile client cannot grow it without bound.
     *
     * @return array<string,mixed>
     */
    public function cspReport(ApiRequest $r): array
    {
        $body   = $r->body();
        $report = \is_array($body['csp-report'] ?? null) ? $body['csp-report'] : [];
        $dir    = strtolower(trim((string) ($report['effective-directive'] ?? $report['violated-directive'] ?? '')));
        $dir    = (string) preg_replace('/[^a-z-].*$/', '', $dir);
        if ($dir === '') {
            return ['stored' => false];
        }
        $blocked = trim((string) ($report['blocked-uri'] ?? ''));
        if (preg_match('#^[a-z][a-z0-9+.-]*://([^/?\#]+)#i', $blocked, $m)) {
            $blocked = strtolower((string) preg_replace('/:\d+$/', '', $m[1]));
        } elseif ($blocked === '' || $blocked === 'inline' || $blocked === 'eval' || $blocked === 'self') {
            $blocked = $blocked === '' ? 'inline' : $blocked;
        } else {
            $blocked = preg_replace('/[^a-z0-9:.-]/i', '', substr($blocked, 0, 40)) ?: 'other';
        }
        $blocked = mb_substr($blocked, 0, 255);
        $path    = (string) parse_url((string) ($report['document-uri'] ?? ''), PHP_URL_PATH);
        $sample  = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $path) ?? '', 0, 255);
        $hash    = sha1($dir . '|' . $blocked);
        $now     = Factory::getDate()->toSql();
        $db      = $this->db();

        $total = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_secopt_csp')))->loadResult();
        $query = $db->createQuery()->setQuery(
            'INSERT INTO ' . $db->quoteName('#__uxstudio_secopt_csp') . ' (' . implode(', ', $db->quoteName(['hash', 'directive', 'blocked', 'hits', 'first_seen', 'last_seen', 'sample'])) . ') '
            . 'VALUES (:h, :d, :b, 1, :n1, :n2, :s) ON DUPLICATE KEY UPDATE ' . $db->quoteName('hits') . ' = ' . $db->quoteName('hits') . ' + 1, ' . $db->quoteName('last_seen') . ' = VALUES(' . $db->quoteName('last_seen') . ')'
        )->bind(':h', $hash)->bind(':d', $dir)->bind(':b', $blocked)->bind(':n1', $now)->bind(':n2', $now)->bind(':s', $sample);
        if ($total >= 2000) {
            // Table full: only already known reports are counted.
            $query = $db->createQuery()->update($db->quoteName('#__uxstudio_secopt_csp'))->set($db->quoteName('hits') . ' = ' . $db->quoteName('hits') . ' + 1')
                ->set($db->quoteName('last_seen') . ' = :n')->where($db->quoteName('hash') . ' = :h')->bind(':n', $now)->bind(':h', $hash);
        }
        $db->setQuery($query)->execute();

        return ['stored' => true];
    }

    /* ------------------------------------------------------- Central App feed */

    /**
     * Data for the Central App connector (docs/CA-INTEGRACE.md). Routes: security-monitor, ip-bans (GET), ip-bans/sync (POST).
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function caFeed(string $route, array $query, array $body): array
    {
        return match ($route) {
            'security-monitor' => $this->securityMonitor(),
            'ip-bans'          => $this->bansReport(),
            'ip-bans/sync'     => $this->syncBans($body),
            default            => throw new ApiException('Unknown route.', 404, 'not_found'),
        };
    }

    /** @return array<string,mixed> */
    private function bansReport(): array
    {
        return $this->bans()->report() + ['firewall_active' => !empty($this->s()['ip_firewall_enabled'])];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function syncBans(array $body): array
    {
        $items  = isset($body['bans']) && \is_array($body['bans']) ? $body['bans'] : [];
        $result = $this->bans()->replaceCentral($items, $this->clientIp());

        if ($result['truncated'] > 0) {
            Log::add('Central ban sync: ' . $result['truncated'] . ' ranges over the per-ban limit were not stored.', Log::WARNING, 'uxstudio');
        }

        return ['success' => true, 'synced' => $result['synced'], 'skipped' => $result['skipped'], 'truncated' => $result['truncated'], 'central' => $result['synced']];
    }

    /**
     * Same top-level keys as the WordPress report (score = incidents (0 until Joomla incident sources exist), hardening.risk_count) plus a
     * Joomla "checks" list. Sections this module cannot fill are reported with available:false.
     *
     * @return array<string,mixed>
     */
    private function securityMonitor(): array
    {
        $h         = new Hardening($this->db(), $this->ctx()->app());
        $checks    = $h->checks($this->s());
        $posture   = array_filter($checks, static fn (array $c): bool => !\in_array($c['id'], ['headers', 'generator', 'csp', 'login_limit', 'firewall'], true));
        $problems  = array_filter($posture, static fn (array $c): bool => $c['status'] !== 'ok');
        $flags     = $h->hardeningFlags();
        $risk      = (int) $flags['debug_display'] + (int) $flags['wpconfig_writable'] + (int) $flags['installation_dir'] + (int) $flags['leaky_files'] + (int) !$flags['force_ssl'];
        $superUser = $h->superUsers();
        $stats     = $this->guard()->stats(24);

        return [
            'available'      => true,
            // Incidents only (like the WordPress report: changed files, integrity, new admin accounts). Joomla has no incident
            // source yet, so the score stays 0 and the posture findings are reported separately (posture_problems, checks).
            'score'          => 0,
            'posture_problems' => \count($problems),
            'checks'         => $checks,
            'cron'           => ['available' => false],
            'files'          => ['available' => false, 'unresolved_count' => 0, 'severity_max' => 'clean', 'last_scan_at' => null, 'last_full_scan' => null, 'full_scan_files' => 0, 'findings' => []],
            'integrity'      => ['available' => false],
            'new_users'      => ['available' => false, 'count' => 0, 'admin_count' => 0, 'items' => []],
            'admin_security' => [
                // No data on new administrators / role escalations / new-IP logins yet (needs the Joomla action log): not available.
                'available'        => false,
                'new_admins'       => [],
                'role_escalations' => [],
                'new_ip_logins'    => [],
                'super_users'      => \count($superUser),
                'super_users_without_mfa' => array_values(array_column(array_filter($superUser, static fn (array $u): bool => !$u['mfa']), 'username')),
            ],
            'hardening'      => $flags + ['risk_count' => $risk],
            'login'          => ['failed_24h' => $stats['fail'], 'lockouts_24h' => $stats['lockout'], 'blocked_24h' => $stats['blocked'], 'captcha_24h' => $stats['captcha'], 'active_locks' => \count($this->guard()->activeLocks())],
            'firewall'       => ['enabled' => !empty($this->s()['ip_firewall_enabled']), 'active_bans' => $this->bans()->activeCount()],
            'csp'            => ['mode' => $this->s()['csp_mode'], 'level' => $this->s()['csp_level']],
            'checked_at'     => Factory::getDate()->toSql(),
        ];
    }
}

<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Hooks;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Site side of UX1 Network app (CA) protocol, see docs/CA-INTEGRACE.md:
 *  - pairing (browser form POST to the CA, key generated here and stored encrypted),
 *  - the signed node endpoint index.php?uxstudio_node=<route> (Node.php),
 *  - single sign-on into the administration (Sso.php),
 *  - CaClient for calls from other modules to the CA.
 * Other modules deliver data by implementing caFeed(string $route, array $query, array $body): array.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        // The pairing form of the administration POSTs to UX1 Network app: an enforced CSP (security-optimization) must allow it.
        if ($ctx->isAdmin()) {
            Hooks::add('csp.sources', function (array $sources) use ($ctx): array {
                $base  = Pairing::normalizeBaseUrl((string) $ctx->setting('ca_url', ''));
                $parts = $base !== null ? parse_url($base) : false;
                if (\is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                    $host = (string) $parts['host'];
                    $sources['form-action'][] = $parts['scheme'] . '://' . (str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host) . (isset($parts['port']) ? ':' . $parts['port'] : '');
                }

                return $sources;
            });
        }
        // Both entry points run in onAfterInitialise, before Joomla routes the request, and always end the request.
        if ($ctx->isSite() && isset($_GET[Node::QUERY]) && \is_string($_GET[Node::QUERY])) {
            (new Node($ctx, $this->store()))->handle();
        } elseif ($ctx->isAdmin() && isset($_GET[Sso::QUERY]) && \is_string($_GET[Sso::QUERY])) {
            (new Sso($ctx, $this->store()))->redeem($ctx->app());
        }
    }

    public function acl(): string
    {
        return 'core.admin';
    }

    private function store(): Store
    {
        return new Store($this->ctx()->db());
    }

    /* ---------------------------------------------------------------- settings */

    public function settingsSchema(): array
    {
        return [
            ['key' => 'ca_url', 'type' => 'text', 'label' => 'UXS_CA_CONNECTOR_URL', 'help' => 'UXS_CA_CONNECTOR_URL_HELP', 'default' => '', 'maxlength' => 200,
             'placeholder' => 'https://app.ux1.cz/', 'section' => 'UXS_CA_CONNECTOR_SECTION_CONNECTION'],
            ['key' => 'node_api_key', 'type' => 'password', 'label' => 'UXS_CA_CONNECTOR_KEY', 'help' => 'UXS_CA_CONNECTOR_KEY_HELP', 'section' => 'UXS_CA_CONNECTOR_SECTION_CONNECTION'],
            ['key' => 'node_allowed_ips', 'type' => 'textarea', 'label' => 'UXS_CA_CONNECTOR_ALLOWED_IPS', 'help' => 'UXS_CA_CONNECTOR_ALLOWED_IPS_HELP', 'default' => '', 'maxlength' => 1000,
             'section' => 'UXS_CA_CONNECTOR_SECTION_CONNECTION'],

            ['key' => 'sso_allow_admin', 'type' => 'toggle', 'label' => 'UXS_CA_CONNECTOR_SSO_ADMIN', 'help' => 'UXS_CA_CONNECTOR_SSO_ADMIN_HELP', 'default' => false, 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
            ['key' => 'sso_mapping_strategy', 'type' => 'select', 'label' => 'UXS_CA_CONNECTOR_SSO_STRATEGY', 'help' => 'UXS_CA_CONNECTOR_SSO_STRATEGY_HELP', 'default' => 'auto',
             'options' => ['auto' => 'UXS_CA_CONNECTOR_SSO_STRATEGY_AUTO', 'strict' => 'UXS_CA_CONNECTOR_SSO_STRATEGY_STRICT', 'disabled' => 'UXS_CA_CONNECTOR_SSO_STRATEGY_DISABLED'],
             'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
            ['key' => 'sso_provision_role', 'type' => 'select', 'label' => 'UXS_CA_CONNECTOR_SSO_PROVISION', 'help' => 'UXS_CA_CONNECTOR_SSO_PROVISION_HELP', 'default' => 'Manager',
             'options' => ['Manager' => 'Manager', 'Editor' => 'Editor'], 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO', 'show_if' => ['key' => 'sso_mapping_strategy', 'equals' => 'auto']],
            ['key' => 'sso_require_ua_match', 'type' => 'toggle', 'label' => 'UXS_CA_CONNECTOR_SSO_UA', 'help' => 'UXS_CA_CONNECTOR_SSO_UA_HELP', 'default' => true, 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
            ['key' => 'sso_require_ip_match', 'type' => 'toggle', 'label' => 'UXS_CA_CONNECTOR_SSO_IP', 'help' => 'UXS_CA_CONNECTOR_SSO_IP_HELP', 'default' => false, 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
            ['key' => 'sso_token_ttl', 'type' => 'number', 'label' => 'UXS_CA_CONNECTOR_SSO_TTL', 'help' => 'UXS_CA_CONNECTOR_SSO_TTL_HELP', 'default' => 60, 'min' => 10, 'max' => 300, 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
            ['key' => 'sso_max_issue_per_hour', 'type' => 'number', 'label' => 'UXS_CA_CONNECTOR_SSO_RATE', 'help' => 'UXS_CA_CONNECTOR_SSO_RATE_HELP', 'default' => 30, 'min' => 1, 'max' => 500, 'section' => 'UXS_CA_CONNECTOR_SECTION_SSO'],
        ];
    }

    /* ---------------------------------------------------------------- data, jobs */

    public function migrations(): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_caconn_kv` (`k` VARCHAR(64) NOT NULL, `v` TEXT NOT NULL, PRIMARY KEY (`k`)) ' . $opts,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_caconn_nonce` (`nonce_hash` CHAR(64) NOT NULL, `created` DATETIME NOT NULL, PRIMARY KEY (`nonce_hash`), KEY `idx_created` (`created`)) ' . $opts,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_caconn_sso` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `token_hash` CHAR(64) NOT NULL, '
                . '`operator_email` VARCHAR(190) NOT NULL DEFAULT \'\', `operator_id` INT NOT NULL DEFAULT 0, `central_role` VARCHAR(64) NOT NULL DEFAULT \'\', '
                . '`target_role` VARCHAR(64) NOT NULL DEFAULT \'\', `user_id` INT NOT NULL DEFAULT 0, `ua_hash` CHAR(64) NOT NULL DEFAULT \'\', `ip` VARCHAR(45) NOT NULL DEFAULT \'\', '
                . '`return_to` VARCHAR(255) NOT NULL DEFAULT \'\', `created` DATETIME NOT NULL, `expires` DATETIME NOT NULL, `consumed` DATETIME NULL, `revoked` DATETIME NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_token` (`token_hash`), KEY `idx_created` (`created`)) ' . $opts,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_caconn_log` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `event` VARCHAR(40) NOT NULL, '
                . '`ok` TINYINT NOT NULL DEFAULT 1, `detail` VARCHAR(500) NOT NULL DEFAULT \'\', `ip` VARCHAR(45) NOT NULL DEFAULT \'\', `user_id` INT NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_event_ip` (`event`, `ip`, `created`)) ' . $opts,
            ],
        ];
    }

    public function retention(): array
    {
        return [
            ['table' => '#__uxstudio_caconn_nonce', 'column' => 'created', 'days' => 1],
            ['table' => '#__uxstudio_caconn_sso', 'column' => 'created', 'days' => 30],
            ['table' => '#__uxstudio_caconn_log', 'column' => 'created', 'days' => 90],
        ];
    }

    public function tasks(): array
    {
        return [
            'refresh-updates' => ['label' => 'UXS_CA_CONNECTOR_JOB_UPDATES', 'callable' => [$this, 'refreshUpdates'], 'interval' => 'PT12H'],
        ];
    }

    /** Scheduler job ca-connector.refresh-updates: Joomla's own update check, so that the "updates" route has fresh data. */
    public function refreshUpdates(): string
    {
        $count = (new Health($this->ctx()->db()))->refresh();

        return $count . ' update(s) available';
    }

    public function dashboardWidget(): ?array
    {
        try {
            $store  = $this->store();
            $paired = $store->get('paired_at') !== '' && (string) $this->ctx()->setting('node_api_key', '') !== '';
            $last   = $store->get('last_contact');

            return [
                'title'  => Text::_('UXS_CA_CONNECTOR_NAME'),
                'value'  => Text::_($paired ? 'UXS_CA_CONNECTOR_WIDGET_PAIRED' : 'UXS_CA_CONNECTOR_WIDGET_NOT_PAIRED'),
                'text'   => $last !== '' ? Text::sprintf('UXS_CA_CONNECTOR_WIDGET_LAST', $last . ' UTC') : '',
                'status' => $paired ? 'ok' : 'warn',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ---------------------------------------------------------------- administrator API */

    public function routes(): array
    {
        return [
            'GET status'        => [$this, 'status'],
            'GET log'           => [$this, 'log'],
            'POST prepare'      => [$this, 'prepare'],
            'POST connected'    => [$this, 'connected'],
            'POST disconnect'   => [$this, 'disconnect'],
            'POST self-check'   => [$this, 'selfCheck'],
            'POST revoke-sso'   => [$this, 'revokeSso'],
        ];
    }

    /** @return array<string,mixed> */
    public function status(ApiRequest $r): array
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        $s     = $ctx->settings();
        $base  = Pairing::normalizeBaseUrl((string) $s['ca_url']);
        $key   = (string) $s['node_api_key'];
        $site  = CaClient::siteUrl();

        return [
            'ca_url'          => (string) $s['ca_url'],
            'ca_url_effective' => $base ?? '',
            'default_url'     => Pairing::DEFAULT_URL,
            'key_set'         => $key !== '',
            'paired'          => $key !== '' && $store->get('paired_at') !== '',
            'awaiting'        => $key !== '' && $store->get('paired_at') === '' && $store->get('prepared_at') !== '',
            'paired_at'       => $store->get('paired_at') ?: null,
            'prepared_at'     => $store->get('prepared_at') ?: null,
            'last_contact'    => $store->get('last_contact') ?: null,
            'site_url'        => $site,
            'site_name'       => (string) $ctx->app()->get('sitename', ''),
            'site_uid'        => $store->get('site_uid'),
            'node_url'        => $site . '/index.php?' . Node::QUERY . '=ping',
            'capabilities'    => (new Node($ctx, $store))->capabilities(),
            'enabled_modules' => $ctx->kernel()->registry()->enabledIds(),
            'sso'             => [
                'allow_admin' => (bool) $s['sso_allow_admin'],
                'strategy'    => (string) ($s['sso_mapping_strategy'] ?? 'auto'),
                'provision'   => ($s['sso_mapping_strategy'] ?? 'auto') === 'auto',
                'outstanding' => $store->countOutstandingSso(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function log(ApiRequest $r): array
    {
        return ['items' => $this->store()->recentLog($r->int('limit', 50))];
    }

    /**
     * POST ca-connector/prepare {ca_url?, rotate?}: stores the address, creates the key and the site id (the CA calls this
     * site back during pairing, so the key must exist BEFORE the browser leaves) and returns the form the SPA posts to the CA.
     *
     * @return array<string,mixed>
     */
    public function prepare(ApiRequest $r): array
    {
        $ctx      = $this->ctx();
        $store    = $this->store();
        $settings = $ctx->kernel()->settings();
        $typed    = trim($r->string('ca_url', (string) $ctx->setting('ca_url', '')));
        $base     = Pairing::normalizeBaseUrl($typed);
        if ($base === null) {
            throw new ApiException(Text::_('UXS_CA_CONNECTOR_ERR_URL'), 422, 'invalid_url');
        }

        $key = (string) $ctx->setting('node_api_key', '');
        $new = ['ca_url' => $typed === '' ? '' : $base];
        if ($key === '' || $r->bool('rotate')) {
            $key                 = Hmac::generateKey();
            $new['node_api_key'] = $key;
            $store->delete('paired_at'); // a new key means UX1 Network app has to confirm again
        }
        $settings->save($ctx->id(), $new);

        $uid = $store->get('site_uid');
        if ($uid === '') {
            $uid = Pairing::uuid();
            $store->set('site_uid', $uid);
        }
        $store->set('prepared_at', gmdate('Y-m-d H:i:s'));
        CaClient::rememberSiteUrl($store);

        $site   = CaClient::siteUrl();
        $name   = trim((string) $ctx->app()->get('sitename', '')) ?: (string) parse_url($site, PHP_URL_HOST);
        $return = Uri::base() . 'index.php?option=com_uxstudio#/module?id=ca-connector';
        $form   = Pairing::form($base, $site, $name, $key, $return, time(), $uid);
        $store->log('prepare', true, $base);

        return ['form' => $form, 'ca_url' => $base];
    }

    /** POST ca-connector/connected: the browser came back from UX1 Network app with ux1_connected=1. */
    public function connected(ApiRequest $r): array
    {
        $store = $this->store();
        if ((string) $this->ctx()->setting('node_api_key', '') === '') {
            throw new ApiException(Text::_('UXS_CA_CONNECTOR_ERR_NO_KEY'), 409, 'no_key');
        }
        // The return link alone proves nothing: pairing counts only after a signed request from the CA arrived since prepare.
        $prepared = $store->get('prepared_at');
        $contact  = $store->get('last_contact');
        $ok       = $contact !== '' && $contact >= $prepared;
        if ($ok) {
            $store->set('paired_at', gmdate('Y-m-d H:i:s'));
        }
        $store->log('connected', $ok, $ok ? '' : 'awaiting confirmation from the CA', (int) $r->user()->id);

        return $this->status($r) + ['confirmed' => $ok];
    }

    /** POST ca-connector/disconnect: forgets the key and the pairing, revokes SSO tokens. The CA entry has to be removed there. */
    public function disconnect(ApiRequest $r): array
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        $ctx->kernel()->settings()->save($ctx->id(), ['node_api_key' => '']);
        $store->delete('paired_at');
        $store->delete('last_contact');
        $store->revokeAllSso();
        $store->log('disconnect', true, '', (int) $r->user()->id);

        return $this->status($r);
    }

    /** POST ca-connector/revoke-sso */
    public function revokeSso(ApiRequest $r): array
    {
        return ['revoked' => (new Sso($this->ctx(), $this->store()))->revokeAll()];
    }

    /** POST ca-connector/self-check: a signed ping to this very site, proves that the endpoint is reachable and signs correctly. */
    public function selfCheck(ApiRequest $r): array
    {
        $key = (string) $this->ctx()->setting('node_api_key', '');
        if ($key === '') {
            throw new ApiException(Text::_('UXS_CA_CONNECTOR_ERR_NO_KEY'), 409, 'no_key');
        }
        $url   = CaClient::siteUrl() . '/index.php?' . Node::QUERY . '=ping';
        $ts    = time();
        $nonce = Hmac::generateNonce();
        try {
            $res = $this->ctx()->http()->get($url, [
                'timeout'       => 10,
                'allow_private' => true, // our own address, fixed
                'headers'       => [
                    Hmac::HEADER_SIGNATURE => Hmac::sign('GET', $url, '', $ts, $nonce, $key),
                    Hmac::HEADER_TIMESTAMP => (string) $ts,
                    Hmac::HEADER_NONCE     => $nonce,
                    'X-UXS-Selfcheck'      => '1',
                ],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
        $json = json_decode($res['body'], true);
        $ok   = $res['status'] === 200 && \is_array($json) && ($json['connector'] ?? '') === 'uxjoomla';
        $sig  = $res['headers']['x-content-sync-response-signature'] ?? '';

        return [
            'ok'           => $ok && $sig !== '' && hash_equals(Hmac::responseSignature($nonce, $res['body'], $key), $sig),
            'status'       => $res['status'],
            'error'        => $ok ? '' : (\is_array($json) ? (string) ($json['message'] ?? '') : Text::_('UXS_CA_CONNECTOR_ERR_NOT_JSON')),
            'capabilities' => \is_array($json) ? ($json['capabilities'] ?? []) : [],
        ];
    }
}

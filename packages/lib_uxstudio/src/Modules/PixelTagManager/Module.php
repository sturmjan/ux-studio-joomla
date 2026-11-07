<?php

namespace Ux1\UxStudio\Modules\PixelTagManager;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Hooks;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Pixel & Tag Manager: GA4, Google Ads, GTM, Meta Pixel, Microsoft Clarity, Pinterest and Seznam retargeting.
 *
 * Only a tiny external loader (media/.../ptm.js) is added to the page through the WebAssetManager; the validated IDs and
 * the consent rules travel in a data attribute, so there is no inline script and the page works with a strict CSP
 * (script-src 'nonce-...'). The loader injects a provider only after the visitor consented to its category
 * (analytics / marketing) and re-checks when the consent changes.
 */
final class Module extends BaseModule
{
    /** Names of extensions that look like a cookie consent solution (element names). */
    private const CONSENT_EXTENSIONS = '~cookie|consent|gdpr|complianz|cookiebot|iubenda|osano|termly|cmplz|klaro~i';

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'inject']);
            // An enforced CSP (security-optimization) must let the configured providers load: they contribute their own hosts.
            Hooks::add('csp.sources', [$this, 'cspSources']);
        }
    }

    /**
     * Hook csp.sources: hosts of the providers that have a valid ID (per directive, only what the provider needs).
     *
     * @param array<string,string[]> $sources
     *
     * @return array<string,string[]>
     */
    public function cspSources(array $sources): array
    {
        $ctx = $this->ctx();
        foreach (Providers::all() as $provider => $def) {
            if (Providers::clean($provider, $ctx->setting(Providers::settingKey($provider), '')) === '') {
                continue;
            }
            foreach ($def['csp'] as $directive => $hosts) {
                foreach ($hosts as $host) {
                    $sources[$directive][] = 'https://' . $host;
                }
            }
        }

        return $sources;
    }

    /** onBeforeCompileHead: add the loader with the configuration to public HTML pages. */
    public function inject(Event $event): void
    {
        $ctx = $this->ctx();
        $app = $ctx->app();
        $doc = $app->getDocument();
        if (!$doc instanceof HtmlDocument || $app->getInput()->getCmd('format', 'html') !== 'html') {
            return;
        }
        if ($ctx->setting('skip_staff', true)) {
            $user = $app->getIdentity();
            if ($user && !$user->guest && $user->authorise('core.manage', 'com_uxstudio')) {
                return;
            }
        }
        $config = $this->clientConfig();
        if (!$config['ids']) {
            return;
        }

        $ctx->useScript('ptm.js', [
            'defer'       => true,
            'data-config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ]);
    }

    /**
     * Configuration handed to the loader: validated IDs and consent rules. Nothing secret is in it.
     *
     * @return array{ids:array<string,string>,consent:array<string,mixed>}
     */
    private function clientConfig(): array
    {
        $ctx = $this->ctx();
        $ids = [];
        foreach (array_keys(Providers::all()) as $provider) {
            $id = Providers::clean($provider, $ctx->setting(Providers::settingKey($provider), ''));
            if ($id !== '') {
                $ids[$provider] = $id;
            }
        }

        $mode    = (string) $ctx->setting('consent_mode', 'uxstudio');
        $consent = ['mode' => \in_array($mode, ['uxstudio', 'cookie', 'off'], true) ? $mode : 'uxstudio'];
        if ($consent['mode'] === 'cookie') {
            foreach (['analytics', 'marketing'] as $cat) {
                $consent[$cat] = [
                    'cookie' => $this->cookieName((string) $ctx->setting('consent_' . $cat . '_cookie', '')),
                    'match'  => mb_substr(trim((string) $ctx->setting('consent_' . $cat . '_match', '')), 0, 80),
                ];
            }
        }

        return ['ids' => $ids, 'consent' => $consent];
    }

    /** Cookie names are limited to the RFC 6265 token characters. */
    private function cookieName(string $name): string
    {
        $name = trim($name);

        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) ? $name : '';
    }

    public function settingsSchema(): array
    {
        $field = static fn (string $key, string $group): array => [
            'key' => $key . '_id', 'type' => 'text', 'label' => 'UXS_PTM_' . strtoupper($key) . '_ID', 'help' => 'UXS_PTM_' . strtoupper($key) . '_ID_HELP',
            'default' => '', 'maxlength' => 40, 'section' => 'UXS_PTM_SECTION_TAGS', 'group' => $group, 'placeholder' => '',
        ];
        $ga4     = $field('ga4', 'UXS_PTM_GROUP_GOOGLE');
        $ga4['placeholder'] = 'G-XXXXXXXXXX';
        $ads     = $field('ads', 'UXS_PTM_GROUP_GOOGLE');
        $ads['placeholder'] = 'AW-123456789';
        $gtm     = $field('gtm', 'UXS_PTM_GROUP_GOOGLE');
        $gtm['placeholder'] = 'GTM-XXXXXXX';
        $meta    = $field('meta', 'UXS_PTM_GROUP_META');
        $meta['placeholder'] = '1234567890123456';
        $clarity = $field('clarity', 'UXS_PTM_GROUP_MICROSOFT');
        $clarity['placeholder'] = 'abcdefghij';
        $pin     = $field('pinterest', 'UXS_PTM_GROUP_PINTEREST');
        $pin['placeholder'] = '2612345678901';
        $seznam  = $field('seznam', 'UXS_PTM_GROUP_SEZNAM');
        $seznam['placeholder'] = '123456';

        $sec = 'UXS_PTM_SECTION_CONSENT';
        $cookieShow = ['key' => 'consent_mode', 'equals' => 'cookie'];

        return [
            $ga4, $ads, $gtm, $meta, $clarity, $pin, $seznam,
            [
                'key' => 'consent_mode', 'type' => 'select', 'label' => 'UXS_PTM_CONSENT_MODE', 'help' => 'UXS_PTM_CONSENT_MODE_HELP', 'default' => 'uxstudio', 'section' => $sec,
                'options' => ['uxstudio' => 'UXS_PTM_CONSENT_UXSTUDIO', 'cookie' => 'UXS_PTM_CONSENT_COOKIE', 'off' => 'UXS_PTM_CONSENT_OFF'],
            ],
            ['key' => 'consent_analytics_cookie', 'type' => 'text', 'label' => 'UXS_PTM_CONSENT_ANALYTICS_COOKIE', 'help' => 'UXS_PTM_CONSENT_COOKIE_HELP', 'default' => '', 'maxlength' => 64, 'section' => $sec, 'show_if' => $cookieShow],
            ['key' => 'consent_analytics_match', 'type' => 'text', 'label' => 'UXS_PTM_CONSENT_ANALYTICS_MATCH', 'help' => 'UXS_PTM_CONSENT_MATCH_HELP', 'default' => '', 'maxlength' => 80, 'section' => $sec, 'show_if' => $cookieShow],
            ['key' => 'consent_marketing_cookie', 'type' => 'text', 'label' => 'UXS_PTM_CONSENT_MARKETING_COOKIE', 'help' => 'UXS_PTM_CONSENT_COOKIE_HELP', 'default' => '', 'maxlength' => 64, 'section' => $sec, 'show_if' => $cookieShow],
            ['key' => 'consent_marketing_match', 'type' => 'text', 'label' => 'UXS_PTM_CONSENT_MARKETING_MATCH', 'help' => 'UXS_PTM_CONSENT_MATCH_HELP', 'default' => '', 'maxlength' => 80, 'section' => $sec, 'show_if' => $cookieShow],
            ['key' => 'skip_staff', 'type' => 'toggle', 'label' => 'UXS_PTM_SKIP_STAFF', 'help' => 'UXS_PTM_SKIP_STAFF_HELP', 'default' => true, 'section' => $sec],
        ];
    }

    public function routes(): array
    {
        return ['GET status' => [$this, 'status']];
    }

    public function dashboardWidget(): ?array
    {
        $count = \count($this->clientConfig()['ids']);
        $mode  = (string) $this->ctx()->setting('consent_mode', 'uxstudio');

        return [
            'title'  => $this->ctx()->t('UXS_PTM_WIDGET_TITLE'),
            'value'  => $count,
            'text'   => $this->ctx()->t('UXS_PTM_WIDGET_TEXT'),
            'status' => $count > 0 && $mode === 'off' ? 'warn' : 'ok',
        ];
    }

    /** GET pixel-tag-manager/status: configured providers, consent setup and the hosts a CSP must allow. */
    public function status(ApiRequest $r): array
    {
        $ctx       = $this->ctx();
        $providers = [];
        $hosts     = [];
        foreach (Providers::all() as $key => $def) {
            $raw   = trim((string) $ctx->setting(Providers::settingKey($key), ''));
            $valid = Providers::clean($key, $raw);
            $providers[] = [
                'key'      => $key,
                'label'    => $def['label'],
                'category' => $def['category'],
                'id'       => $valid !== '' ? $valid : $raw,
                'state'    => $raw === '' ? 'unset' : ($valid !== '' ? 'active' : 'invalid'),
            ];
            if ($valid !== '') {
                $hosts = array_merge($hosts, $def['hosts']);
            }
        }

        $mode     = (string) $ctx->setting('consent_mode', 'uxstudio');
        $detected = $this->detectConsentSolutions();
        $registry = $ctx->kernel()->registry();
        if ($registry->has('consent') && $registry->isEnabled('consent')) {
            array_unshift($detected, 'UX Studio Consent');
        }

        // In "cookie" mode a category with active tags but no usable cookie name can never be allowed: say so.
        $badCookie = [];
        if ($mode === 'cookie') {
            foreach (['analytics', 'marketing'] as $cat) {
                $hasTags = \count(array_filter($providers, static fn (array $p): bool => $p['state'] === 'active' && $p['category'] === $cat)) > 0;
                if ($hasTags && $this->cookieName((string) $ctx->setting('consent_' . $cat . '_cookie', '')) === '') {
                    $badCookie[] = $cat;
                }
            }
        }

        return [
            'bad_cookie'   => $badCookie,
            'providers'    => $providers,
            'consent_mode' => $mode,
            'detected'     => $detected,
            'hosts'        => array_values(array_unique($hosts)),
            'active'       => \count(array_filter($providers, static fn (array $p): bool => $p['state'] === 'active')),
        ];
    }

    /** @return string[] names of enabled extensions that look like a consent solution */
    private function detectConsentSolutions(): array
    {
        $db   = $this->ctx()->db();
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['name', 'element', 'folder']))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('enabled') . ' = 1')->where($db->quoteName('type') . ' IN (' . $db->quote('plugin') . ',' . $db->quote('module') . ',' . $db->quote('component') . ')')
        )->loadAssocList();
        $found = [];
        foreach ($rows as $row) {
            // Joomla's own "privacy consent" (registration terms) is not a cookie banner.
            if (!\in_array($row['folder'], ['privacy', 'authentication', 'user', 'actionlog'], true) && !\in_array($row['element'], ['privacyconsent', 'consents'], true) && preg_match(self::CONSENT_EXTENSIONS, (string) $row['element'])) {
                $found[(string) $row['element']] = (string) $row['element'];
            }
        }

        return array_values($found);
    }
}

<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

\defined('_JEXEC') or die;

/**
 * Builds the Content-Security-Policy header value from the module settings.
 *
 * Levels: "basic" keeps inline scripts and eval() allowed (compatible with most extensions and page builders),
 * "strict" drops eval() and, when a nonce is available (Joomla stamps it on every script / style it renders), also
 * 'unsafe-inline' for scripts. Presets add the hosts of well known services; custom sources and extra directives are
 * validated token by token, so a typo in the settings can never produce a header with a broken syntax.
 */
final class CspPolicy
{
    /** preset id => [label, directive => sources]. */
    public const PRESETS = [
        'google-analytics' => ['Google Analytics', ['script-src' => ['https://www.googletagmanager.com', 'https://www.google-analytics.com'], 'connect-src' => ['https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'], 'img-src' => ['https://www.google-analytics.com', 'https://www.googletagmanager.com']]],
        'google-tag-manager' => ['Google Tag Manager', ['script-src' => ['https://www.googletagmanager.com'], 'frame-src' => ['https://www.googletagmanager.com'], 'img-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://www.googletagmanager.com']]],
        'google-fonts' => ['Google Fonts', ['style-src' => ['https://fonts.googleapis.com'], 'font-src' => ['https://fonts.gstatic.com']]],
        'google-maps' => ['Google Maps', ['script-src' => ['https://maps.googleapis.com', 'https://maps.gstatic.com'], 'frame-src' => ['https://www.google.com', 'https://maps.google.com'], 'img-src' => ['https://maps.googleapis.com', 'https://maps.gstatic.com', 'https://*.googleusercontent.com'], 'connect-src' => ['https://maps.googleapis.com']]],
        'recaptcha' => ['Google reCAPTCHA', ['script-src' => ['https://www.google.com', 'https://www.gstatic.com'], 'frame-src' => ['https://www.google.com', 'https://recaptcha.google.com']]],
        'turnstile' => ['Cloudflare Turnstile', ['script-src' => ['https://challenges.cloudflare.com'], 'frame-src' => ['https://challenges.cloudflare.com']]],
        'cloudflare-insights' => ['Cloudflare Web Analytics', ['script-src' => ['https://static.cloudflareinsights.com'], 'connect-src' => ['https://cloudflareinsights.com']]],
        'youtube' => ['YouTube', ['frame-src' => ['https://www.youtube.com', 'https://www.youtube-nocookie.com'], 'img-src' => ['https://i.ytimg.com'], 'script-src' => ['https://www.youtube.com']]],
        'vimeo' => ['Vimeo', ['frame-src' => ['https://player.vimeo.com'], 'img-src' => ['https://i.vimeocdn.com'], 'script-src' => ['https://player.vimeo.com']]],
        'facebook-pixel' => ['Meta (Facebook) Pixel', ['script-src' => ['https://connect.facebook.net'], 'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'], 'img-src' => ['https://www.facebook.com'], 'frame-src' => ['https://www.facebook.com']]],
        'hotjar' => ['Hotjar', ['script-src' => ['https://static.hotjar.com', 'https://script.hotjar.com'], 'connect-src' => ['https://*.hotjar.com', 'wss://*.hotjar.com', 'https://*.hotjar.io'], 'img-src' => ['https://*.hotjar.com'], 'font-src' => ['https://script.hotjar.com'], 'frame-src' => ['https://vars.hotjar.com']]],
        'stripe' => ['Stripe', ['script-src' => ['https://js.stripe.com'], 'frame-src' => ['https://js.stripe.com', 'https://hooks.stripe.com'], 'connect-src' => ['https://api.stripe.com']]],
        'paypal' => ['PayPal', ['script-src' => ['https://www.paypal.com', 'https://www.paypalobjects.com'], 'frame-src' => ['https://www.paypal.com'], 'connect-src' => ['https://www.paypal.com'], 'img-src' => ['https://www.paypalobjects.com']]],
        'mapy-cz' => ['Mapy.cz / Seznam', ['script-src' => ['https://api.mapy.cz', 'https://*.seznam.cz'], 'img-src' => ['https://*.mapy.cz', 'https://*.seznam.cz'], 'connect-src' => ['https://api.mapy.cz', 'https://*.seznam.cz'], 'frame-src' => ['https://mapy.cz']]],
    ];

    private const DIRECTIVES = ['default-src', 'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem', 'style-src-attr', 'img-src', 'font-src', 'connect-src',
        'media-src', 'object-src', 'frame-src', 'child-src', 'worker-src', 'manifest-src', 'form-action', 'frame-ancestors', 'base-uri', 'upgrade-insecure-requests', 'block-all-mixed-content'];

    /** @return array<string,string> preset id => label */
    public static function presetLabels(): array
    {
        return array_map(static fn (array $p): string => $p[0], self::PRESETS);
    }

    /**
     * @param array<string,mixed> $settings module settings
     * @param string              $nonce    CSP nonce already stamped by Joomla on scripts / styles ('' = none)
     * @param string              $reportUri absolute URL that collects violation reports ('' = none)
     */
    public static function build(array $settings, string $nonce = '', string $reportUri = ''): string
    {
        $strict = ($settings['csp_level'] ?? 'basic') === 'strict';

        /** @var array<string,string[]> $d */
        $d = [
            'default-src'     => ["'self'"],
            'script-src'      => ["'self'"],
            'style-src'       => ["'self'", "'unsafe-inline'"],
            'img-src'         => ["'self'", 'data:', 'https:'],
            'font-src'        => ["'self'", 'data:'],
            'connect-src'     => ["'self'"],
            'media-src'       => ["'self'", 'data:'],
            'frame-src'       => ["'self'"],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];
        if ($strict && $nonce !== '') {
            $d['script-src'][] = "'nonce-" . $nonce . "'";
        } else {
            $d['script-src'][] = "'unsafe-inline'";
        }
        if (!$strict) {
            $d['script-src'][] = "'unsafe-eval'";
        }

        foreach ((array) ($settings['csp_presets'] ?? []) as $id) {
            foreach (self::PRESETS[(string) $id][1] ?? [] as $directive => $sources) {
                $d[$directive] = array_merge($d[$directive] ?? ["'self'"], $sources);
            }
        }

        // Custom allowlist: a host applies to every fetch directive that matters for external content.
        foreach (self::sources((string) ($settings['csp_custom_allowlist'] ?? '')) as $source) {
            foreach (['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src'] as $directive) {
                $d[$directive][] = $source;
            }
        }

        // Extra directives: "directive value value" per line; they replace the generated directive of the same name.
        foreach (preg_split('/\r\n|\r|\n/', (string) ($settings['csp_extra_directives'] ?? '')) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim(rtrim(trim($line), ';')));
            $name  = strtolower((string) array_shift($parts));
            if ($name === '' || !\in_array($name, self::DIRECTIVES, true)) {
                continue;
            }
            $values = self::sources(implode(' ', $parts), true);
            if (\in_array($name, ['upgrade-insecure-requests', 'block-all-mixed-content'], true)) {
                $d[$name] = [];
            } elseif ($values !== []) {
                $d[$name] = $values;
            }
        }

        $out = [];
        foreach ($d as $directive => $sources) {
            $sources = array_values(array_unique($sources));
            $out[]   = trim($directive . ' ' . implode(' ', $sources));
        }
        if ($reportUri !== '') {
            $out[] = 'report-uri ' . $reportUri;
        }

        return implode('; ', $out);
    }

    /**
     * Valid CSP source expressions from free text: hosts, schemes and the quoted keywords. Anything with a character that
     * could break out of the header (semicolon, comma, quote other than the keyword form) is dropped.
     *
     * @return string[]
     */
    public static function sources(string $raw, bool $allowKeywords = false): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if ($allowKeywords && preg_match("/^'(self|none|unsafe-inline|unsafe-eval|strict-dynamic|wasm-unsafe-eval)'$/", $token)) {
                $out[] = $token;
            } elseif (preg_match('#^(https?|wss?)://(\*\.)?[A-Za-z0-9.-]+(:(\d{1,5}|\*))?(/[A-Za-z0-9._~/%-]*)?$#', $token)
                || preg_match('#^(\*\.)?[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}(:(\d{1,5}|\*))?$#', $token)
                || preg_match('/^(https?|wss?|data|blob|mediastream|filesystem):$/', $token)) {
                $out[] = $token;
            }
        }

        return array_values(array_unique($out));
    }
}

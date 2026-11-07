<?php

namespace Ux1\UxStudio\Modules\PerformanceOptimization;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Front-end speed-ups that Joomla does not do itself: lazy loading, deferred scripts, preconnect / preload hints,
 * HTML minification, removal of unneeded core assets and browser cache headers (.htaccess, only on request).
 * Joomla's own page cache and gzip are NOT reimplemented: the admin screen shows their state and recommends them.
 */
final class Module extends BaseModule
{
    private const AS_TYPES = ['style', 'script', 'font', 'image', 'fetch'];
    private const MAX_LINES = 40;

    public function boot(ModuleContext $ctx): void
    {
        if (!$ctx->isSite()) {
            return;
        }
        $ctx->on('onBeforeCompileHead', [$this, 'head']);
        // Last, so markup added by other modules and plugins is rewritten too.
        $ctx->on('onAfterRender', [$this, 'rewrite'], Priority::MIN);
    }

    // ------------------------------------------------------------------ site

    /** onBeforeCompileHead: resource hints and removal of unneeded assets. */
    public function head(Event $event): void
    {
        $doc = $this->ctx()->app()->getDocument();
        if (!$doc instanceof HtmlDocument) {
            return;
        }
        foreach (self::preconnectHints($this->lines('preconnect')) as $hint) {
            // Without `crossorigin` the connection is for plain (no-CORS) requests: scripts, images. With it, for fonts / fetch.
            $doc->addHeadLink($hint['origin'], 'preconnect', 'rel', $hint['crossorigin'] ? ['crossorigin' => ''] : []);
        }
        $base = rtrim(Uri::root(true), '/');
        foreach (self::preloads($this->lines('preload')) as $p) {
            $attrs = ['as' => $p['as']];
            if ($p['type'] !== '') {
                $attrs['type'] = $p['type'];
            }
            if ($p['as'] === 'font') {
                $attrs['crossorigin'] = 'anonymous';
            }
            // A root-relative path is a path inside this site (also when the site lives in a sub-folder); the head renderer
            // does not escape the href.
            $url = $p['url'];
            if ($base !== '' && $url[0] === '/' && $url !== $base && !str_starts_with($url, $base . '/')) {
                $url = $base . $url;
            }
            $doc->addHeadLink(str_replace('&', '&amp;', $url), 'preload', 'rel', $attrs);
        }

        $disable = self::assetList($this->lines('disable_assets'));
        if ($this->ctx()->setting('remove_jquery_migrate', false)) {
            $disable[] = ['script', 'jquery-migrate'];
        }
        if ($disable) {
            $wa = $doc->getWebAssetManager();
            foreach ($disable as [$type, $name]) {
                try {
                    if ($wa->assetExists($type, $name)) {
                        $wa->disableAsset($type, $name);
                    }
                } catch (\Throwable $e) {
                    // Unknown asset or a locked manager: nothing to remove.
                }
            }
        }
    }

    /** onAfterRender: lazy loading, defer, minification. */
    public function rewrite(Event $event): void
    {
        $app = $this->ctx()->app();
        $doc = $app->getDocument();
        if (!$doc instanceof HtmlDocument || $doc->getType() !== 'html') {
            return;
        }
        $body = $app->getBody();
        if (!\is_string($body) || stripos($body, '</html>') === false) {
            return;
        }
        $rewriter = $this->rewriter();
        if (!$rewriter->enabled()) {
            return;
        }
        try {
            $app->setBody($rewriter->process($body));
        } catch (\Throwable $e) {
            // The page must never break because of an optimization.
        }
    }

    private function rewriter(): Rewriter
    {
        $c = $this->ctx();

        return new Rewriter([
            'lazy_images'    => (bool) $c->setting('lazy_images', true),
            'lazy_iframes'   => (bool) $c->setting('lazy_iframes', true),
            'lazy_skip'      => max(0, min(10, (int) $c->setting('lazy_skip', 1))),
            'lcp_priority'   => (bool) $c->setting('lcp_priority', true),
            'lazy_exclude'   => $this->lines('lazy_exclude'),
            'defer_scripts'  => (bool) $c->setting('defer_scripts', false),
            'defer_exclude'  => $this->lines('defer_exclude'),
            'minify_html'    => (bool) $c->setting('minify_html', false),
            'strip_comments' => (bool) $c->setting('strip_comments', false),
        ]);
    }

    /** @return string[] non-empty trimmed lines of a textarea setting */
    private function lines(string $key): array
    {
        $raw   = (string) $this->ctx()->setting($key, '');
        $lines = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && mb_strlen($line) <= 300) {
                $lines[] = $line;
            }
            if (\count($lines) >= self::MAX_LINES) {
                break;
            }
        }

        return $lines;
    }

    /**
     * Valid origins (scheme://host[:port]) from user lines, optionally followed by the word `crossorigin`;
     * anything else is dropped.
     *
     * @param  string[] $lines
     * @return array<int,array{origin:string,crossorigin:bool}>
     */
    public static function preconnectHints(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!preg_match('~^(https?)://([A-Za-z0-9.-]+)(:\d{1,5})?/?(\s+crossorigin)?$~i', $line, $m)) {
                continue;
            }
            $origin = strtolower($m[1] . '://' . $m[2]) . ($m[3] ?? '');
            $out[$origin . (empty($m[4]) ? '' : '#cors')] = ['origin' => $origin, 'crossorigin' => !empty($m[4])];
        }

        return array_values($out);
    }

    /**
     * @param  string[] $lines
     * @return string[]
     */
    public static function preconnectOrigins(array $lines): array
    {
        return array_values(array_unique(array_column(self::preconnectHints($lines), 'origin')));
    }

    /**
     * "url as [type]" lines. The URL must be absolute http(s) or root-relative; `as` is whitelisted.
     *
     * @param  string[] $lines
     * @return array<int,array{url:string,as:string,type:string}>
     */
    public static function preloads(array $lines): array
    {
        $types = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];
        $out   = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];
            $url   = $parts[0] ?? '';
            $as    = strtolower($parts[1] ?? '');
            if (!preg_match('~^(https?://[A-Za-z0-9.-]+(:\d{1,5})?)?/(?!/)[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]*$~', $url) || !\in_array($as, self::AS_TYPES, true)) {
                continue;
            }
            $type = preg_match('~^[a-z]+/[a-z0-9.+-]+$~i', $parts[2] ?? '') ? $parts[2] : '';
            if ($type === '' && $as === 'font' && preg_match('~\.(woff2|woff|ttf|otf)(\?|#|$)~i', $url, $m)) {
                $type = $types[strtolower($m[1])];
            }
            $out[] = ['url' => $url, 'as' => $as, 'type' => $type];
        }

        return $out;
    }

    /**
     * "script:name", "style:name" or "name" (both) lines to [type, name] pairs.
     *
     * @param  string[] $lines
     * @return array<int,array{0:string,1:string}>
     */
    public static function assetList(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $type = null;
            if (preg_match('/^(script|style):(.+)$/i', $line, $m)) {
                $type = strtolower($m[1]);
                $line = trim($m[2]);
            }
            if (!preg_match('/^[A-Za-z0-9._@\/-]{1,120}$/', $line)) {
                continue;
            }
            foreach ($type !== null ? [$type] : ['script', 'style'] as $t) {
                $out[] = [$t, $line];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ settings

    public function settingsSchema(): array
    {
        $p = 'UXS_PERFORMANCE_OPTIMIZATION_';
        $s = static fn (string $name): string => $p . 'SECTION_' . $name;

        return [
            ['key' => 'lazy_images', 'type' => 'toggle', 'label' => $p . 'LAZY_IMAGES', 'help' => $p . 'LAZY_IMAGES_HELP', 'default' => true, 'section' => $s('IMAGES')],
            ['key' => 'lazy_iframes', 'type' => 'toggle', 'label' => $p . 'LAZY_IFRAMES', 'help' => $p . 'LAZY_IFRAMES_HELP', 'default' => true, 'section' => $s('IMAGES')],
            ['key' => 'lazy_skip', 'type' => 'number', 'label' => $p . 'LAZY_SKIP', 'help' => $p . 'LAZY_SKIP_HELP', 'default' => 1, 'min' => 0, 'max' => 10, 'section' => $s('IMAGES'), 'show_if' => ['key' => 'lazy_images', 'truthy' => true]],
            ['key' => 'lcp_priority', 'type' => 'toggle', 'label' => $p . 'LCP_PRIORITY', 'help' => $p . 'LCP_PRIORITY_HELP', 'default' => true, 'section' => $s('IMAGES')],
            ['key' => 'lazy_exclude', 'type' => 'textarea', 'label' => $p . 'LAZY_EXCLUDE', 'help' => $p . 'LAZY_EXCLUDE_HELP', 'default' => '', 'section' => $s('IMAGES'), 'wide' => true],

            ['key' => 'defer_scripts', 'type' => 'toggle', 'label' => $p . 'DEFER_SCRIPTS', 'help' => $p . 'DEFER_SCRIPTS_HELP', 'default' => false, 'section' => $s('SCRIPTS')],
            ['key' => 'defer_exclude', 'type' => 'textarea', 'label' => $p . 'DEFER_EXCLUDE', 'help' => $p . 'DEFER_EXCLUDE_HELP', 'default' => "jquery", 'section' => $s('SCRIPTS'), 'wide' => true, 'show_if' => ['key' => 'defer_scripts', 'truthy' => true]],
            ['key' => 'remove_jquery_migrate', 'type' => 'toggle', 'label' => $p . 'REMOVE_MIGRATE', 'help' => $p . 'REMOVE_MIGRATE_HELP', 'default' => false, 'section' => $s('SCRIPTS')],
            ['key' => 'disable_assets', 'type' => 'textarea', 'label' => $p . 'DISABLE_ASSETS', 'help' => $p . 'DISABLE_ASSETS_HELP', 'default' => '', 'section' => $s('SCRIPTS'), 'wide' => true],

            ['key' => 'preconnect', 'type' => 'textarea', 'label' => $p . 'PRECONNECT', 'help' => $p . 'PRECONNECT_HELP', 'default' => '', 'section' => $s('HINTS'), 'wide' => true, 'placeholder' => "https://fonts.gstatic.com crossorigin\nhttps://www.googletagmanager.com"],
            ['key' => 'preload', 'type' => 'textarea', 'label' => $p . 'PRELOAD', 'help' => $p . 'PRELOAD_HELP', 'default' => '', 'section' => $s('HINTS'), 'wide' => true, 'placeholder' => '/media/templates/site/cassiopeia/fonts/example.woff2 font'],

            ['key' => 'minify_html', 'type' => 'toggle', 'label' => $p . 'MINIFY_HTML', 'help' => $p . 'MINIFY_HTML_HELP', 'default' => false, 'section' => $s('HTML')],
            ['key' => 'strip_comments', 'type' => 'toggle', 'label' => $p . 'STRIP_COMMENTS', 'help' => $p . 'STRIP_COMMENTS_HELP', 'default' => false, 'section' => $s('HTML')],

            ['key' => 'cache_ttl', 'type' => 'select', 'label' => $p . 'CACHE_TTL', 'help' => $p . 'CACHE_TTL_HELP', 'default' => 'month', 'section' => $s('CACHE'), 'options' => [
                'week'     => $p . 'TTL_WEEK',
                'month'    => $p . 'TTL_MONTH',
                'halfyear' => $p . 'TTL_HALFYEAR',
                'year'     => $p . 'TTL_YEAR',
            ]],
        ];
    }

    public function dashboardWidget(): ?array
    {
        $c      = $this->ctx();
        $active = 0;
        foreach (['lazy_images', 'lazy_iframes', 'lcp_priority', 'defer_scripts', 'minify_html', 'strip_comments', 'remove_jquery_migrate'] as $key) {
            $active += $c->setting($key, false) ? 1 : 0;
        }
        $htaccess = CacheHeaders::status()['active'];

        return [
            'title'  => $c->t('UXS_PERFORMANCE_OPTIMIZATION_NAME'),
            'value'  => $active + ($htaccess ? 1 : 0),
            'text'   => $c->t('UXS_PERFORMANCE_OPTIMIZATION_WIDGET_TEXT'),
            'status' => $active > 0 ? 'ok' : 'warn',
        ];
    }

    public function onDisable(): void
    {
        try {
            CacheHeaders::remove();
        } catch (\Throwable $e) {
            // A read-only .htaccess cannot be cleaned; the admin sees the block there and can delete it by hand.
        }
    }

    // ------------------------------------------------------------------ API

    public function routes(): array
    {
        return [
            'GET status'            => [$this, 'status'],
            'POST audit'            => [$this, 'audit'],
            'POST htaccess/apply'   => ['handler' => [$this, 'htaccessApply'], 'acl' => 'core.admin'],
            'POST htaccess/remove'  => ['handler' => [$this, 'htaccessRemove'], 'acl' => 'core.admin'],
        ];
    }

    /** GET performance-optimization/status */
    public function status(ApiRequest $r): array
    {
        $c = $this->ctx();

        return [
            'builtin'  => (new Audit($c->db()))->builtin($c->app()),
            'htaccess' => CacheHeaders::status(),
            'features' => [
                'lazy_images'    => (bool) $c->setting('lazy_images', true),
                'lazy_iframes'   => (bool) $c->setting('lazy_iframes', true),
                'lcp_priority'   => (bool) $c->setting('lcp_priority', true),
                'defer_scripts'  => (bool) $c->setting('defer_scripts', false),
                'minify_html'    => (bool) $c->setting('minify_html', false),
                'strip_comments' => (bool) $c->setting('strip_comments', false),
                'jquery_migrate' => (bool) $c->setting('remove_jquery_migrate', false),
                'hints'          => \count(self::preconnectOrigins($this->lines('preconnect'))) + \count(self::preloads($this->lines('preload'))),
            ],
            'ttl'      => (string) $c->setting('cache_ttl', 'month'),
        ];
    }

    /** POST performance-optimization/audit - fetches the home page once and reports what it found. */
    public function audit(ApiRequest $r): array
    {
        $c = $this->ctx();
        try {
            return (new Audit($c->db()))->page();
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException($c->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_FETCH'), 502, 'fetch_failed');
        }
    }

    /** POST performance-optimization/htaccess/apply {confirm:true, ttl?} - needs the explicit confirmation of an administrator. */
    public function htaccessApply(ApiRequest $r): array
    {
        $c = $this->ctx();
        if (!$r->bool('confirm')) {
            throw new ApiException($c->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_CONFIRM'), 422, 'confirmation_required');
        }
        if (!CacheHeaders::isApache()) {
            throw new ApiException($c->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_NOT_APACHE'), 422, 'not_apache');
        }
        $ttl = $r->string('ttl', (string) $c->setting('cache_ttl', 'month'));
        if (!isset(CacheHeaders::TTLS[$ttl])) {
            throw new ApiException($c->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_TTL'), 422, 'invalid_value');
        }
        try {
            $backup = CacheHeaders::apply($ttl);
        } catch (\RuntimeException $e) {
            throw new ApiException($c->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_WRITE'), 500, 'write_failed');
        }

        return ['backup' => $backup, 'htaccess' => CacheHeaders::status()];
    }

    /** POST performance-optimization/htaccess/remove */
    public function htaccessRemove(ApiRequest $r): array
    {
        if (!CacheHeaders::remove()) {
            throw new ApiException($this->ctx()->t('UXS_PERFORMANCE_OPTIMIZATION_ERR_WRITE'), 500, 'write_failed');
        }

        return ['htaccess' => CacheHeaders::status()];
    }
}

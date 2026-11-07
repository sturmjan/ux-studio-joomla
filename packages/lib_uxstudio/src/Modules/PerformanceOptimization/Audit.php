<?php

namespace Ux1\UxStudio\Modules\PerformanceOptimization;

use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * Read-only look at what Joomla itself already does for speed (page cache, gzip, OPcache) plus a fetch of the home page
 * that counts the things this module can fix. Produces recommendation codes; the admin screen turns them into text.
 */
final class Audit
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /** Built-in Joomla / PHP features. We only show and recommend them, never change them. @return array<string,mixed> */
    public function builtin(object $app): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('enabled'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('cache'));
        $pageCache = (int) $this->db->setQuery($query)->loadResult() === 1;
        $opcache   = \function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);

        return [
            'joomla_cache' => (int) $app->get('caching', 0),   // 0 off, 1 conservative, 2 progressive
            'gzip'         => (bool) $app->get('gzip', false),
            'page_cache'   => $pageCache,
            'debug'        => (bool) $app->get('debug', false),
            'opcache'      => $opcache,
            'php'          => PHP_VERSION,
        ];
    }

    /**
     * Fetches the site's home page and measures it.
     *
     * @return array<string,mixed>
     */
    public function page(): array
    {
        $url   = Uri::root();
        $start = microtime(true);
        // Our own, fixed URL: the SSRF guard would refuse localhost installs, and the Http class refuses redirects to other hosts.
        $res = (new Http())->get($url, ['timeout' => 15, 'allow_private' => true, 'headers' => ['Accept-Encoding' => 'gzip, br']]);
        $ms  = (int) round((microtime(true) - $start) * 1000);
        $html = $res['body'];

        $images = preg_match_all('~<img\b[^>]*>~i', $html, $im) ?: 0;
        $lazyImages = 0;
        foreach ($im[0] ?? [] as $tag) {
            if (preg_match('/\sloading\s*=\s*["\']?lazy/i', $tag)) {
                $lazyImages++;
            }
        }
        $iframes = preg_match_all('~<iframe\b[^>]*>~i', $html, $fr) ?: 0;
        $lazyFrames = 0;
        foreach ($fr[0] ?? [] as $tag) {
            if (preg_match('/\sloading\s*=\s*["\']?lazy/i', $tag)) {
                $lazyFrames++;
            }
        }
        $scripts = 0;
        $blocking = 0;
        $firstAsset = '';
        if (preg_match_all('~<script\b([^>]*)>~i', $html, $sc)) {
            foreach ($sc[1] as $attrs) {
                if (!preg_match('/\ssrc\s*=\s*["\']?([^"\'\s>]+)/i', ' ' . $attrs, $src)) {
                    continue;
                }
                $scripts++;
                if (!preg_match('/\s(defer|async)(\s|=|$)/i', ' ' . $attrs) && !preg_match('/\stype\s*=\s*["\']?module/i', ' ' . $attrs)) {
                    $blocking++;
                }
                if ($firstAsset === '' && $this->isLocal($src[1])) {
                    $firstAsset = $src[1];
                }
            }
        }
        $styles = preg_match_all('~<link\b[^>]*\brel\s*=\s*["\']?stylesheet~i', $html) ?: 0;
        if ($firstAsset === '' && preg_match('~<link\b[^>]*\brel\s*=\s*["\']?stylesheet[^>]*\bhref\s*=\s*["\']?([^"\'\s>]+)~i', $html, $css) && $this->isLocal($css[1])) {
            $firstAsset = $css[1];
        }

        $inline = 0;
        if (preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $html, $in)) {
            foreach ($in[1] as $body) {
                $inline += \strlen($body);
            }
        }

        $asset = $firstAsset !== '' ? $this->assetHeaders($firstAsset, $url) : null;

        return [
            'url'            => $url,
            'status'         => (int) $res['status'],
            'time_ms'        => $ms,
            'html_bytes'     => \strlen($html),
            'encoding'       => (string) ($res['headers']['content-encoding'] ?? ''),
            'cache_control'  => (string) ($res['headers']['cache-control'] ?? ''),
            'images'         => $images,
            'images_lazy'    => $lazyImages,
            'iframes'        => $iframes,
            'iframes_lazy'   => $lazyFrames,
            'scripts'        => $scripts,
            'scripts_blocking' => $blocking,
            'styles'         => $styles,
            'inline_js_bytes' => $inline,
            'asset'          => $asset,
            'comments'       => preg_match_all('~<!--(?!\[if|<!\[endif).*?-->~s', $html) ?: 0,
        ];
    }

    private function isLocal(string $src): bool
    {
        if (str_starts_with($src, '//')) {
            return strcasecmp((string) parse_url('http:' . $src, PHP_URL_HOST), (string) parse_url(Uri::root(), PHP_URL_HOST)) === 0;
        }

        return !preg_match('~^[a-z][a-z0-9+.-]*:~i', $src) || strcasecmp((string) parse_url($src, PHP_URL_HOST), (string) parse_url(Uri::root(), PHP_URL_HOST)) === 0;
    }

    /** @return array{url:string,cache_control:string,expires:string,status:int}|null */
    private function assetHeaders(string $src, string $base): ?array
    {
        $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
        if (str_starts_with($src, '//')) {
            $url = (parse_url($base, PHP_URL_SCHEME) ?: 'http') . ':' . $src;
        } elseif (preg_match('~^https?://~i', $src)) {
            $url = $src;
        } else {
            $root = rtrim($base, '/');
            $url  = (str_starts_with($src, '/') ? ($this->origin($base)) : $root . '/') . ltrim($src, '/');
        }
        try {
            $res = (new Http())->request('HEAD', $url, ['timeout' => 8, 'allow_private' => true]);
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'url'           => $url,
            'status'        => (int) $res['status'],
            'cache_control' => (string) ($res['headers']['cache-control'] ?? ''),
            'expires'       => (string) ($res['headers']['expires'] ?? ''),
        ];
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/';
    }
}

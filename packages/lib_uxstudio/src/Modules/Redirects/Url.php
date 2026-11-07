<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * URL normalisation shared by the rule store, the front-end engine and the 404 log, so a source saved by a human, an
 * import or the suggester always produces the same match key as the live request.
 *
 * Match key = site-relative path, percent-decoded, lowercased, without a trailing slash (except "/") and without the
 * "/index.php" prefix of sites that run without URL rewriting, plus an optional query string with its params sorted.
 * Case and trailing slash are ignored on purpose: old sites often ran on case-insensitive servers and search engines
 * keep both variants indexed. A non-SEF address ("/index.php?option=com_content&id=3") keeps its "/index.php" path.
 */
final class Url
{
    /** Paths a rule may never take over (would lock the admin out). */
    private const RESERVED = ['/administrator', '/api', '/cli'];

    /** Subdirectory the site lives in ('' at the domain root, '/joomla5' locally), no trailing slash. */
    public static function homePath(): string
    {
        return rtrim(Uri::root(true), '/');
    }

    /** Host of this site, lowercase. */
    public static function homeHost(): string
    {
        return strtolower((string) parse_url(Uri::root(), PHP_URL_HOST));
    }

    /**
     * Split anything a user may type (full URL of this or the old site, path, "index.php?..." route) into parts.
     *
     * @return array{host:string,path:string,query:string,fragment:string}|null
     */
    public static function split(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'http://placeholder.invalid' . ($raw[0] === '/' ? '' : '/') . $raw;
        }
        $parts = parse_url($raw);
        if ($parts === false) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return [
            'host'     => $host === 'placeholder.invalid' ? '' : $host,
            'path'     => (string) ($parts['path'] ?? '/'),
            'query'    => (string) ($parts['query'] ?? ''),
            'fragment' => (string) ($parts['fragment'] ?? ''),
        ];
    }

    /**
     * Normalised path and query of a source as typed. A full URL of THIS site loses the subdirectory, a foreign host
     * (the old domain) keeps its path as is.
     *
     * @return array{path:string,path_cs:string,query:string}
     */
    public static function parseSource(string $raw): array
    {
        $parts = self::split($raw);
        if ($parts === null) {
            return ['path' => '', 'path_cs' => '', 'query' => ''];
        }
        $path = $parts['path'];
        if ($parts['host'] === '' || $parts['host'] === self::homeHost()) {
            $path = self::stripHome($path);
        }

        return [
            'path'    => self::normalizePath($path),
            'path_cs' => self::normalizePath($path, true),
            'query'   => self::normalizeQuery($parts['query']),
        ];
    }

    /**
     * The current request as path + query relative to the site root.
     *
     * @return array{path:string,path_cs:string,query:string}
     */
    public static function request(string $requestUri): array
    {
        $parts = self::split($requestUri === '' ? '/' : $requestUri) ?? ['path' => '/', 'query' => ''];
        $path  = self::stripHome($parts['path']);

        return [
            'path'    => self::normalizePath($path),
            'path_cs' => self::normalizePath($path, true),
            'query'   => self::normalizeQuery($parts['query']),
        ];
    }

    /** Removes the site subdirectory and the "/index.php/" prefix from a raw path. */
    public static function stripHome(string $path): string
    {
        $home = self::homePath();
        if ($home !== '' && stripos($path, $home) === 0) {
            $rest = substr($path, \strlen($home));
            if ($rest === '' || $rest[0] === '/') {
                $path = $rest === '' ? '/' : $rest;
            }
        }
        if (preg_match('#^/index\.php/#i', $path)) {
            $path = substr($path, 10);
        }

        return $path;
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /** Normalised path: decoded, single slashes, no trailing slash, lowercase unless $keepCase. */
    public static function normalizePath(string $path, bool $keepCase = false): string
    {
        $path = self::utf8(rawurldecode($path));
        $path = '/' . ltrim((string) preg_replace('~/{2,}~', '/', $path), '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $path = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $path);

        return $keepCase ? $path : mb_strtolower($path, 'UTF-8');
    }

    /** Decoded params sorted, so "?b=2&a=1" and "?a=1&b=2" are the same key. Names are lowercased, values kept. */
    public static function normalizeQuery(string $query): string
    {
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv      = explode('=', $pair, 2);
            $pairs[] = strtolower(self::utf8(rawurldecode(str_replace('+', ' ', $kv[0])))) . (isset($kv[1]) ? '=' . self::utf8(rawurldecode(str_replace('+', ' ', $kv[1]))) : '');
        }
        sort($pairs, SORT_STRING);

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '', implode('&', $pairs));
    }

    public static function key(string $path, string $query = ''): string
    {
        return $query === '' ? $path : $path . '?' . $query;
    }

    /** Index hash of a rule source (or target) key. */
    public static function hash(string $matchType, string $key): string
    {
        return sha1($matchType . '|' . $key);
    }

    /** Whether a normalised path belongs to Joomla internals. */
    public static function isReserved(string $path): bool
    {
        foreach (self::RESERVED as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A Joomla route ("index.php?Itemid=101") that is turned into a SEF URL when the rule fires. */
    public static function isRoute(string $target): bool
    {
        return (bool) preg_match('#^index\.php\?#i', $target);
    }

    /** Whether a target is a site-relative path, a Joomla route or a URL on this host. */
    public static function isInternal(string $url): bool
    {
        if (self::isRoute($url) || ($url !== '' && $url[0] === '/' && !str_starts_with($url, '//'))) {
            return true;
        }
        $parts = self::split($url);
        if ($parts === null || $parts['host'] === '' || $parts['host'] !== self::homeHost()) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return true;
        }
        // Same host, but a foreign port (or a downgrade to http on an https site) is another site: keep it absolute.
        [$scheme, $port] = self::originOf($url);
        [$homeScheme, $homePort] = self::originOf(Uri::root());

        return $port === $homePort && ($scheme === $homeScheme || ($scheme === 'http' && $homeScheme === 'https'));
    }

    /** Exactly the origin of this site (scheme, host and port), used before a request is sent without the SSRF guard. */
    public static function isSameOrigin(string $url): bool
    {
        $parts = self::split($url);
        if ($parts === null || $parts['host'] === '' || $parts['host'] !== self::homeHost() || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        return self::originOf($url) === self::originOf(Uri::root());
    }

    /** @return array{0:string,1:int} lowercase scheme and port (the default port of the scheme when none is given) */
    private static function originOf(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port   = parse_url($url, PHP_URL_PORT);

        return [$scheme, $port !== null && $port !== false ? (int) $port : ($scheme === 'https' ? 443 : 80)];
    }

    /** Site-relative form of an internal URL ("/kontakt?a=1"), keeping case and slash. */
    public static function toRelative(string $url): string
    {
        if (self::isRoute($url) || ($url !== '' && $url[0] === '/' && !str_starts_with($url, '//'))) {
            return $url;
        }
        $parts = self::split($url);
        if ($parts === null) {
            return $url;
        }
        $path = self::stripHome($parts['path']);

        return $path . ($parts['query'] !== '' ? '?' . $parts['query'] : '') . ($parts['fragment'] !== '' ? '#' . $parts['fragment'] : '');
    }

    /** Absolute URL for a stored target (a route goes through the site router, a path gets the site root). */
    public static function absolute(string $target): string
    {
        if (self::isRoute($target)) {
            try {
                return Route::link('site', $target, false, Route::TLS_IGNORE, true);
            } catch (\Throwable) {
                return rtrim(Uri::root(), '/') . '/' . $target;
            }
        }
        if ($target !== '' && $target[0] === '/' && !str_starts_with($target, '//')) {
            return rtrim(Uri::root(), '/') . self::frontController($target) . $target;
        }

        return $target;
    }

    /**
     * "/index.php" for a site-relative path on a SEF site without URL rewriting (there every page lives below
     * /index.php), '' otherwise. Real files (media, images, index.php itself) are left alone.
     */
    private static function frontController(string $path): string
    {
        $app = Factory::getApplication();
        if (!$app->get('sef') || $app->get('sef_rewrite') || preg_match('#^/index\.php(/|\?|$)#i', $path)) {
            return '';
        }
        $file = (string) parse_url('http://x' . $path, PHP_URL_PATH);
        $real = JPATH_ROOT . '/' . ltrim(rawurldecode($file), '/');

        return $file === '/' || is_file($real) ? '' : '/index.php';
    }

    /** Match key of an internal target (chain detection), '' for external targets and routes. */
    public static function targetKey(string $target, bool $keepCase = false): string
    {
        if ($target === '' || self::isRoute($target) || !self::isInternal($target)) {
            return '';
        }
        $parts = self::split(self::toRelative($target));
        if ($parts === null) {
            return '';
        }

        return self::key(self::normalizePath($parts['path'], $keepCase), self::normalizeQuery($parts['query']));
    }
}

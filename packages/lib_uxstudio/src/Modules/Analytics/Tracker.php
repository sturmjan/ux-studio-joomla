<?php

namespace Ux1\UxStudio\Modules\Analytics;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\RateLimiter;
use Ux1\UxStudio\Modules\BotThrottle\Guard;

\defined('_JEXEC') or die;

/**
 * Validates and stores one cookieless page view. Nothing personal is stored: the raw IP and User-Agent are only
 * fed into a one-way hash whose salt is random per day and deleted after two days, so a visitor cannot be linked
 * across days and the hashes cannot be reversed even with the database.
 */
final class Tracker
{
    private const BOT_PATTERN = '~bot\b|bot/|crawl|spider|slurp|scrape|fetch|monitor|uptime|pingdom|headless|phantom|lighthouse|pagespeed|gtmetrix|'
        . 'curl|wget|python|java/|libwww|okhttp|go-http|httpclient|axios|node-fetch|postman|facebookexternalhit|preview|validator|'
        . 'whatsapp|telegram|discord|semrush|ahrefs|mj12|dotbot|petalbot|bingpreview|chrome-lighthouse|siteimprove|zabbix|nagios~i';

    /** @param array<string,mixed> $settings */
    public function __construct(private DatabaseInterface $db, private array $settings)
    {
    }

    public static function isBot(string $ua): bool
    {
        return $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    /** Today's date (Y-m-d) in the site time zone. */
    public static function today(): string
    {
        $offset = (string) Factory::getApplication()->get('offset', 'UTC');
        try {
            $zone = new \DateTimeZone($offset);
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    /** Normalises the path sent by the beacon: site path only, no query except Joomla's non-SEF routing, max 255 chars. */
    public static function cleanPath(string $path): string
    {
        $path  = substr(trim($path), 0, 2048);
        $parts = parse_url($path);
        $p     = (string) ($parts['path'] ?? $path);
        $p     = '/' . ltrim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $p), '/');
        $query = (string) ($parts['query'] ?? '');
        if ($query !== '' && preg_match('/(^|&)option=com_[a-z0-9_]+/i', $query)) {
            $keep = [];
            parse_str($query, $q);
            foreach (['option', 'view', 'id', 'catid', 'Itemid', 'layout'] as $k) {
                if (isset($q[$k]) && \is_scalar($q[$k]) && preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', (string) $q[$k])) {
                    $keep[$k] = (string) $q[$k];
                }
            }
            if ($keep) {
                $p .= '?' . http_build_query($keep);
            }
        }

        return mb_substr($p, 0, 255);
    }

    /** Removes the sub-folder the site is installed in (/joomla5), so rules and reports use site-relative paths. */
    public static function stripBase(string $path): string
    {
        $base = rtrim(Uri::root(true), '/');
        if ($base === '') {
            return $path;
        }
        $bare = (string) strtok($path, '?');
        if ($bare === $base || str_starts_with($bare, $base . '/')) {
            $rest = substr($path, \strlen($base));

            return $rest === '' || $rest[0] === '?' ? '/' . $rest : $rest;
        }

        return $path;
    }

    /** True when the path matches one of the exclusion patterns (one per line, prefix or * wildcard). */
    public function isExcluded(string $path): bool
    {
        $bare = (string) strtok($path, '?');
        if (str_starts_with($bare, '/administrator') || str_starts_with($bare, '/api/')) {
            return true;
        }
        foreach (preg_split('/\R/', (string) ($this->settings['exclude_paths'] ?? '')) ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (str_contains($rule, '*') ? fnmatch($rule, $bare) : str_starts_with($bare, $rule)) {
                return true;
            }
        }

        return false;
    }

    /** Stores the view unless it is filtered out. Returns true when a row was written. */
    public function record(string $rawPath, string $ref): bool
    {
        $input = Factory::getApplication()->getInput()->server;
        $ua    = (string) $input->get('HTTP_USER_AGENT', '', 'string');
        $al    = (string) $input->get('HTTP_ACCEPT_LANGUAGE', '', 'string');

        if (!empty($this->settings['filter_bots']) && self::isBot($ua)) {
            return false;
        }
        $purpose = strtolower((string) $input->get('HTTP_SEC_PURPOSE', '', 'string') . (string) $input->get('HTTP_PURPOSE', '', 'string'));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }

        // Cheap shared guard first (a banned IP costs one read), then the generous per-IP cap of the public route itself.
        if (class_exists(Guard::class) && Guard::exceeded('analytics_hit', 20, 10, 900)) {
            return false;
        }
        // A real visitor stays far below this; it bounds what one address (an IPv6 /64 counts as one) can insert per hour.
        if ((new RateLimiter($this->db))->hitClient('analytics:hit-hour', 600, 3600) > 0) {
            return false;
        }

        // An empty / non-string path is not a page view (it would inflate the home page).
        if (trim($rawPath) === '') {
            return false;
        }
        $path = self::stripBase(self::cleanPath($rawPath));
        if ($this->isExcluded($path)) {
            return false;
        }

        $host = '';
        if ($ref !== '') {
            $h    = strtolower((string) parse_url(substr($ref, 0, 2048), PHP_URL_HOST));
            $self = strtolower((string) parse_url(Uri::root(), PHP_URL_HOST));
            // Navigation inside the site is not a "source".
            $host = ($h !== '' && $h !== $self && preg_match('/^[a-z0-9.\-]{1,120}$/', $h)) ? $h : '';
        }

        $trust   = !empty($this->settings['trust_proxy']);
        $ip      = ClientIp::get($trust);
        $country = '';
        // CF-IPCountry is trusted only when the forwarded address was accepted from a trusted proxy (resolved IP differs from the TCP peer).
        $viaProxy = $trust && $ip !== '' && $ip !== (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($viaProxy && preg_match('/^[A-Za-z]{2}$/', (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''))) {
            $country = strtoupper((string) $_SERVER['HTTP_CF_IPCOUNTRY']);
        }
        if ($country === '' || $country === 'XX' || $country === 'T1') {
            $country = GeoIp::lookup($ip);
        }

        $day    = self::today();
        $device = self::device($ua);
        $browser = self::browser($ua);
        $os     = self::os($ua);
        $lang   = self::lang($al);
        $hash   = hash('sha256', $ip . '|' . $ua . '|' . $day . '|' . $this->salt($day));
        $ts     = Factory::getDate()->toSql();
        $hostV  = $host !== '' ? $host : null;
        $cc     = $country !== '' ? $country : null;

        $q = $this->db->createQuery()
            ->insert($this->db->quoteName('#__uxstudio_analytics_hits'))
            ->columns($this->db->quoteName(['path', 'referer_host', 'visitor_hash', 'device', 'browser', 'os', 'lang', 'country', 'day', 'ts']))
            ->values(':path, :ref, :hash, :device, :browser, :os, :lang, :cc, :day, :ts')
            ->bind(':path', $path)
            ->bind(':ref', $hostV, $hostV === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':hash', $hash)
            ->bind(':device', $device)
            ->bind(':browser', $browser)
            ->bind(':os', $os)
            ->bind(':lang', $lang)
            ->bind(':cc', $cc, $cc === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':day', $day)
            ->bind(':ts', $ts);
        $this->db->setQuery($q)->execute();

        return true;
    }

    /** Random per-day salt (stored for the current and the previous day only). */
    private function salt(string $day): string
    {
        $db   = $this->db;
        $name = $db->quoteName('#__uxstudio_analytics_salt');
        $read = fn (): string => (string) $db->setQuery(
            $db->createQuery()->select($db->quoteName('salt'))->from($name)->where($db->quoteName('day') . ' = :d')->bind(':d', $day)
        )->loadResult();

        $salt = $read();
        if ($salt === '') {
            $new = bin2hex(random_bytes(16));
            // INSERT IGNORE: a concurrent request may have created the salt first, we then read theirs.
            $db->setQuery('INSERT IGNORE INTO ' . $name . ' (' . $db->quoteName('day') . ', ' . $db->quoteName('salt') . ') VALUES (' . $db->quote($day) . ', ' . $db->quote($new) . ')')->execute();
            $salt = $read();
            $old  = gmdate('Y-m-d', strtotime($day . ' -2 days'));
            $db->setQuery($db->createQuery()->delete($name)->where($db->quoteName('day') . ' < :o')->bind(':o', $old))->execute();
        }

        return $salt;
    }

    public static function device(string $ua): string
    {
        if ($ua === '') {
            return 'unknown';
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    public static function browser(string $ua): string
    {
        $map = [
            'Edg' => 'Edge', 'OPR' => 'Opera', 'Opera' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'YaBrowser' => 'Yandex',
            'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'CriOS' => 'Chrome', 'Safari' => 'Safari', 'MSIE' => 'Internet Explorer', 'Trident' => 'Internet Explorer',
        ];
        foreach ($map as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        return 'other';
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Windows/i', $ua)               => 'Windows',
            (bool) preg_match('/Android/i', $ua)               => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod|iOS/i', $ua)  => 'iOS',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua)    => 'macOS',
            (bool) preg_match('/Linux/i', $ua)                 => 'Linux',
            default                                            => 'other',
        };
    }

    /** Primary language of Accept-Language ("cs-CZ,cs;q=0.9" -> "cs"). */
    public static function lang(string $al): string
    {
        if ($al === '') {
            return 'unknown';
        }
        $first = strtolower(trim(explode(',', $al)[0]));
        $first = explode('-', explode(';', $first)[0])[0];
        $first = (string) preg_replace('/[^a-z]/', '', $first);

        return $first === '' ? 'unknown' : substr($first, 0, 8);
    }
}

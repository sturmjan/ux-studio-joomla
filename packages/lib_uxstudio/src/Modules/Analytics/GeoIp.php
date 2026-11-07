<?php

namespace Ux1\UxStudio\Modules\Analytics;

use Joomla\CMS\Factory;
use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * IP to country mapping without a licence key. A public-domain database (ip-location-db / geo-whois-asn-country) is
 * downloaded once and stored as a compact binary index (10 B per record: uint32 start, uint32 end, 2 ASCII letters).
 * A lookup is a binary search straight in the file, so the database is never loaded into memory. The raw IP is never
 * stored, a lookup only yields a two letter country code. IPv4 only.
 */
final class GeoIp
{
    private const SRC = 'https://cdn.jsdelivr.net/npm/@ip-location-db/geo-whois-asn-country/geo-whois-asn-country-ipv4.csv';
    private const REC = 10;

    /** @var resource|null */
    private static $fh = null;

    /** @var array<string,mixed>|null per-request cache of status.json */
    private static ?array $statusCache = null;

    /**
     * Data directory: <log_path>/uxs-analytics-geoip (administrator/logs by default). Deliberately NOT in the Joomla
     * cache folder, because System > Clear Cache removes every sub-folder there. Access is denied for the web.
     */
    public static function dir(): string
    {
        $log = (string) Factory::getApplication()->get('log_path', JPATH_ADMINISTRATOR . '/logs');
        $log = rtrim(str_replace('\\', '/', $log !== '' ? $log : JPATH_ADMINISTRATOR . '/logs'), '/');

        return $log . '/uxs-analytics-geoip';
    }

    /** One-off move of the data from the old location in the cache folder. */
    private static function adoptLegacy(): void
    {
        $old = JPATH_ADMINISTRATOR . '/cache/uxstudio-analytics-geoip';
        if (is_dir($old) && is_file($old . '/status.json') && !is_file(self::dir() . '/status.json')) {
            self::ensureDir();
            foreach (['country-ipv4.bin', 'status.json'] as $f) {
                if (is_file($old . '/' . $f)) {
                    @rename($old . '/' . $f, self::dir() . '/' . $f);
                }
            }
        }
    }

    private static function binPath(): string
    {
        return self::dir() . '/country-ipv4.bin';
    }

    /** @return array{enabled?:bool,rows?:int,bytes?:int,updated?:string} */
    public static function status(): array
    {
        if (self::$statusCache !== null) {
            return self::$statusCache;
        }
        $file = self::dir() . '/status.json';
        if (!is_file($file)) {
            self::adoptLegacy();
        }
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return self::$statusCache = (\is_array($json) ? $json : []);
    }

    private static function saveStatus(array $status): void
    {
        self::ensureDir();
        file_put_contents(self::dir() . '/status.json', json_encode($status));
        self::$statusCache = $status;
    }

    public static function isEnabled(): bool
    {
        return !empty(self::status()['enabled']);
    }

    public static function setEnabled(bool $on): void
    {
        $status            = self::status();
        $status['enabled'] = $on;
        self::saveStatus($status);
    }

    public static function isReady(): bool
    {
        return is_file(self::binPath()) && (int) (self::status()['rows'] ?? 0) > 0;
    }

    private static function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Downloads the source and builds the binary index.
     *
     * @return array{0:bool,1:string} ok, message
     */
    public static function update(): array
    {
        self::ensureDir();
        try {
            $resp = (new Http())->get(self::SRC, ['timeout' => 90, 'max_bytes' => 64 * 1024 * 1024]);
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
        if ($resp['status'] !== 200 || $resp['body'] === '') {
            return [false, 'HTTP ' . $resp['status']];
        }

        $tmp = self::binPath() . '.tmp';
        $fh  = @fopen($tmp, 'wb');
        if (!$fh) {
            return [false, 'Cannot write to the data directory.'];
        }
        $rows   = 0;
        $prev   = -1;
        $sorted = true;
        foreach (explode("\n", $resp['body']) as $line) {
            $parts = explode(',', trim($line));
            if (\count($parts) < 3) {
                continue;
            }
            $start = ip2long($parts[0]);
            $end   = ip2long($parts[1]);
            $cc    = strtoupper(substr(trim($parts[2]), 0, 2));
            if ($start === false || $end === false || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $start &= 0xFFFFFFFF;
            $end   &= 0xFFFFFFFF;
            if ($start < $prev) {
                $sorted = false;
            }
            $prev = $start;
            fwrite($fh, pack('NN', $start, $end) . $cc);
            $rows++;
        }
        fclose($fh);

        if (!$sorted || $rows < 1000) {
            @unlink($tmp);

            return [false, $sorted ? 'Too few records (' . $rows . ').' : 'The source is not sorted.'];
        }
        if (self::$fh) {
            fclose(self::$fh);
            self::$fh = null;
        }
        @unlink(self::binPath());
        rename($tmp, self::binPath());
        self::saveStatus(['enabled' => true, 'rows' => $rows, 'bytes' => (int) filesize(self::binPath()), 'updated' => gmdate('c')]);

        return [true, $rows . ' ranges'];
    }

    /** Uppercase ISO country code for an IPv4 address, '' when unknown. */
    public static function lookup(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        $status = self::status();
        if (empty($status['enabled']) || (int) ($status['rows'] ?? 0) < 1 || !is_file(self::binPath())) {
            return '';
        }
        $n = ip2long($ip);
        if ($n === false) {
            return '';
        }
        $n &= 0xFFFFFFFF;
        if (self::$fh === null) {
            self::$fh = @fopen(self::binPath(), 'rb') ?: null;
        }
        if (!self::$fh) {
            return '';
        }
        $lo = 0;
        $hi = intdiv((int) ($status['bytes'] ?? filesize(self::binPath())), self::REC) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (fseek(self::$fh, $mid * self::REC) !== 0) {
                break;
            }
            $rec = fread(self::$fh, self::REC);
            if ($rec === false || \strlen($rec) < self::REC) {
                break;
            }
            $u = unpack('Nstart/Nend', substr($rec, 0, 8));
            if ($n < $u['start']) {
                $hi = $mid - 1;
            } elseif ($n > $u['end']) {
                $lo = $mid + 1;
            } else {
                return substr($rec, 8, 2);
            }
        }

        return '';
    }
}

<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

\defined('_JEXEC') or die;

/**
 * Resolves the real client IP without trusting spoofable headers.
 *
 * Forwarded headers (CF-Connecting-IP, X-Forwarded-For) are honoured ONLY when the TCP peer (REMOTE_ADDR) is a proxy we
 * trust: a Cloudflare edge address (mode "cloudflare") or an address / CIDR the administrator listed as a trusted proxy.
 * For X-Forwarded-For the chain is walked from the right and every trusted proxy hop is skipped, so a value injected by the
 * client (the left side of the chain) is never used. Also provides the CIDR helpers shared by the firewall and BotThrottle.
 */
final class ClientIp
{
    public const MODES = ['none', 'cloudflare', 'xff'];

    /** Published Cloudflare edge ranges (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * @param string                    $mode    none | cloudflare | xff
     * @param array<int,string>         $trusted extra trusted proxy addresses / CIDRs
     * @param array<string,mixed>|null  $server  $_SERVER-like array (tests); defaults to $_SERVER
     */
    public static function resolve(string $mode = 'none', array $trusted = [], ?array $server = null): string
    {
        $server ??= $_SERVER;
        $remote   = self::normalize((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return '';
        }
        if (!\in_array($mode, ['cloudflare', 'xff'], true)) {
            return $remote;
        }

        $peerTrusted = $trusted !== [] && self::matches($remote, $trusted);

        if ($mode === 'cloudflare') {
            if (self::matches($remote, self::CLOUDFLARE) || $peerTrusted) {
                $cf = self::normalize((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
                if ($cf !== '') {
                    return $cf;
                }
            }

            return $remote;
        }

        // xff: only when the peer is a trusted proxy.
        if (!$peerTrusted) {
            return $remote;
        }
        $chain = array_values(array_filter(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')))));
        for ($i = \count($chain) - 1; $i >= 0; $i--) {
            $ip = self::normalize($chain[$i]);
            if ($ip === '') {
                // Garbage in the chain: stop here, do not look further left.
                return $remote;
            }
            if (!self::matches($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** Canonical text form of a valid IP (IPv4-mapped IPv6 becomes IPv4), '' when invalid. */
    public static function normalize(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || @inet_pton($ip) === false) {
            return '';
        }
        $bin = (string) inet_pton($ip);
        if (\strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return (string) inet_ntop($bin);
    }

    /** Splits a textarea (lines or commas) into valid IPs and CIDRs; everything else is dropped. @return string[] */
    public static function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;\s]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::cidrRange($entry) !== null) {
                    $out[] = $entry;
                }
            } elseif (self::normalize($entry) !== '') {
                $out[] = self::normalize($entry);
            }
        }

        return array_values(array_unique($out));
    }

    /** True when the IP equals / falls into any entry of the list (single IPs and CIDRs, IPv4 and IPv6). */
    public static function matches(string $ip, array $entries): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (!str_contains($entry, '/')) {
                $other = @inet_pton($entry);
                if ($other !== false && $other === $bin) {
                    return true;
                }
                continue;
            }
            $range = self::cidrRange($entry);
            if ($range !== null && \strlen($bin) === \strlen($range[0]) && strcmp($bin, $range[0]) >= 0 && strcmp($bin, $range[1]) <= 0) {
                return true;
            }
        }

        return false;
    }

    /** True for a Cloudflare edge address or a listed trusted proxy: such an address is a shared hop, never a single visitor. */
    public static function isEdge(string $ip, array $trusted = []): bool
    {
        return self::matches($ip, self::CLOUDFLARE) || ($trusted !== [] && self::matches($ip, $trusted));
    }

    public static function isLoopback(string $ip): bool
    {
        return self::matches($ip, ['127.0.0.0/8', '::1']);
    }

    /** @return array{0:string,1:string,2:int}|null [start binary, end binary, ip version] */
    public static function cidrRange(string $cidr): ?array
    {
        if (!str_contains($cidr, '/')) {
            return null;
        }
        [$net, $bitsRaw] = explode('/', $cidr, 2);
        $bitsRaw          = trim($bitsRaw);
        $bin              = @inet_pton(trim($net));
        if ($bin === false || !ctype_digit($bitsRaw)) {
            return null;
        }
        $len  = \strlen($bin);
        $bits = (int) $bitsRaw;
        if ($bits > $len * 8) {
            return null;
        }
        $start = '';
        $end   = '';
        for ($i = 0; $i < $len; $i++) {
            $byte = \ord($bin[$i]);
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $start .= \chr($byte & $mask);
            $end   .= \chr(($byte & $mask) | (~$mask & 0xFF));
        }

        return [$start, $end, $len === 4 ? 4 : 6];
    }

    /** Hex ranges for storage: single IP or CIDR => [[ipVersion, startHex, endHex]]. @return array<int,array{0:int,1:string,2:string}> */
    public static function rangesFor(string $value): array
    {
        if (str_contains($value, '/')) {
            $r = self::cidrRange($value);

            return $r === null ? [] : [[$r[2], bin2hex($r[0]), bin2hex($r[1])]];
        }
        $ip = self::normalize($value);
        if ($ip === '') {
            return [];
        }
        $bin = (string) inet_pton($ip);

        return [[\strlen($bin) === 4 ? 4 : 6, bin2hex($bin), bin2hex($bin)]];
    }
}

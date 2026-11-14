<?php

namespace Ux1\UxStudio\Modules\DnsManager;

\defined('_JEXEC') or die;

/**
 * Record classification and input validation. Pure functions, no I/O: UX1 Network app stays authoritative, this is the
 * site-side safety net (a wrong MX silently loses mail, a deleted apex record takes the website down).
 *
 * Risk tags: mx (mail delivery), email_auth (SPF / DKIM / DMARC), web (apex, the site host and www), service (other host records).
 */
final class Guard
{
    public const WRITABLE = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    /** Risks that make a record "critical": deleting it is refused unless the administrator allowed it in the settings. */
    public const CRITICAL = ['mx', 'web'];

    /** @param array<string,mixed> $rec @return list<string> */
    public static function risks(array $rec, string $zone, string $siteHost): array
    {
        $type    = strtoupper((string) ($rec['type'] ?? ''));
        $name    = self::fqdn((string) ($rec['name'] ?? ''), $zone);
        $content = (string) ($rec['content'] ?? '');
        $out     = [];
        if ($type === 'MX') {
            $out[] = 'mx';
        }
        if (($type === 'TXT' && (preg_match('/^\s*"?v=(spf1|dkim1|dmarc1)/i', $content) === 1 || str_starts_with($name, '_dmarc.')))
            || ($type === 'CNAME' && str_contains($name, '_domainkey'))) {
            $out[] = 'email_auth';
        }
        if (\in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $out[] = ($name === $zone || ($siteHost !== '' && $name === $siteHost) || $name === 'www.' . $zone) ? 'web' : 'service';
        }

        return $out;
    }

    public static function isCritical(array $risks): bool
    {
        return array_intersect($risks, self::CRITICAL) !== [];
    }

    /** "@" or "" is the apex, a short label gets the zone appended, an FQDN inside the zone stays. Lower case, no trailing dot. */
    public static function fqdn(string $name, string $zone): string
    {
        $n = strtolower(rtrim(trim($name), '.'));
        $z = strtolower($zone);
        if ($n === '' || $n === '@') {
            return $z;
        }

        return ($n === $z || ($z !== '' && str_ends_with($n, '.' . $z))) ? $n : ($z !== '' ? $n . '.' . $z : $n);
    }

    /**
     * Validates and whitelists a record coming from the browser.
     *
     * @param array<string,mixed> $in
     * @return array{type:string,name:string,content:string,ttl:int,proxied:bool,priority:?int,comment:string}
     * @throws \InvalidArgumentException with a language key as the message
     */
    public static function record(array $in): array
    {
        $type = strtoupper(trim((string) ($in['type'] ?? '')));
        if (!\in_array($type, self::WRITABLE, true)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_TYPE');
        }
        $name = trim((string) ($in['name'] ?? ''));
        $name = $name === '' ? '@' : $name;
        if (str_contains($name, '*')) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_WILDCARD');
        }
        if ($name !== '@' && (\strlen($name) > 253 || preg_match('/^(?!-)[a-z0-9_-]{1,63}(?<!-)(\.(?!-)[a-z0-9_-]{1,63}(?<!-))*\.?$/i', $name) !== 1)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_NAME');
        }
        $content = trim((string) ($in['content'] ?? ''));
        if ($content === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_CONTENT');
        }
        if ($type === 'A' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_IPV4');
        }
        if ($type === 'AAAA' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_IPV6');
        }
        if (($type === 'CNAME' || $type === 'MX') && (\strlen($content) > 253 || preg_match('/^(?!-)[a-z0-9_-]{1,63}(?<!-)(\.(?!-)[a-z0-9_-]{1,63}(?<!-))+\.?$/i', $content) !== 1)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_HOSTNAME');
        }
        if ($type === 'CNAME' && $name === '@') {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_CNAME_APEX');
        }
        if ($type === 'TXT' && mb_strlen($content) > 2048) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_TXT_LENGTH');
        }
        $priority = null;
        if ($type === 'MX') {
            $p = $in['priority'] ?? null;
            $p = filter_var($p, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($p === false) {
                throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_PRIORITY');
            }
            $priority = $p;
        }
        $proxied = \in_array($type, ['A', 'AAAA', 'CNAME'], true) && filter_var($in['proxied'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ttl     = (int) ($in['ttl'] ?? 1);
        if ($proxied) {
            $ttl = 1;
        } elseif ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) {
            throw new \InvalidArgumentException('UXS_DNS_MANAGER_ERR_TTL');
        }
        $comment = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($in['comment'] ?? '')));

        return [
            'type'     => $type,
            'name'     => $name,
            'content'  => $content,
            'ttl'      => $ttl,
            'proxied'  => $proxied,
            'priority' => $priority,
            'comment'  => mb_substr($comment, 0, 100),
        ];
    }
}

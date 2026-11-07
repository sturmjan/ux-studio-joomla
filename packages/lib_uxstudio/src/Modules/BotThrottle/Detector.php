<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Ux1\UxStudio\Core\ClientIp;

\defined('_JEXEC') or die;

/**
 * Stateless-ish bot detection by User-Agent, allow / block lists and optional reverse-DNS verification.
 * The only state is the small answer cache of the rDNS lookups (Kv), because those are slow.
 */
final class Detector
{
    /** @var string[] */
    private array $whitelistUa;
    /** @var string[] */
    private array $whitelistIp;
    /** @var string[] */
    private array $blacklistUa;
    private bool $verifyRdns;
    private ?Kv $kv;

    /** @var callable(string):(string|false) */
    private $reverseLookup;
    /** @var callable(string,bool):string[] */
    private $forwardLookup;

    /**
     * @param array{whitelist_ua?:string[],whitelist_ip?:string[],blacklist_ua?:string[],verify_rdns?:bool,reverse_lookup?:callable,forward_lookup?:callable} $config
     */
    public function __construct(array $config = [], ?Kv $kv = null)
    {
        $this->whitelistUa   = (array) ($config['whitelist_ua'] ?? []);
        $this->whitelistIp   = (array) ($config['whitelist_ip'] ?? []);
        $this->blacklistUa   = (array) ($config['blacklist_ua'] ?? []);
        $this->verifyRdns    = (bool) ($config['verify_rdns'] ?? false);
        $this->kv            = $kv;
        $this->reverseLookup = $config['reverse_lookup'] ?? static fn (string $ip) => @gethostbyaddr($ip);
        $this->forwardLookup = $config['forward_lookup'] ?? [self::class, 'forwardIps'];
    }

    /**
     * Null for non-bots, otherwise category / name / verified.
     * With rDNS verification on, a search engine whose address is PROVEN to belong to someone else is filed under the generic
     * crawlers (throttled hard). A lookup without any answer never counts against a real engine.
     *
     * @return array{category:string,name:string,verified:bool}|null
     */
    public function detect(string $userAgent, string $ip): ?array
    {
        if ($userAgent === '') {
            return null;
        }
        foreach ($this->whitelistUa as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && stripos($userAgent, $needle) !== false) {
                return null;
            }
        }
        if ($this->whitelistIp !== [] && ClientIp::matches($ip, $this->whitelistIp)) {
            return null;
        }
        foreach ($this->blacklistUa as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && stripos($userAgent, $needle) !== false) {
                return ['category' => 'blacklist', 'name' => 'blacklisted-ua', 'verified' => false];
            }
        }

        foreach (Signatures::categories() as $catId => $cat) {
            foreach ($cat['bots'] as $bot) {
                if (@preg_match('#' . $bot['pattern'] . '#i', $userAgent) !== 1) {
                    continue;
                }
                $verified = $this->verifyRdns ? $this->verifyReverseDns($ip, $bot['name']) : true;
                if (!$verified && $catId === 'search_engines') {
                    return ['category' => 'generic_crawlers', 'name' => $bot['name'] . ' (unverified)', 'verified' => false];
                }

                return ['category' => (string) $catId, 'name' => $bot['name'], 'verified' => $verified];
            }
        }

        return null;
    }

    private function verifyReverseDns(string $ip, string $botName): bool
    {
        $verifiable = Signatures::verifiableRdns();
        if (!isset($verifiable[$botName])) {
            return true;
        }
        $cacheKey = 'rdns:' . md5($ip . '|' . $botName);
        $cached   = $this->kv?->get($cacheKey);
        if ($cached !== null) {
            return $cached !== '0';
        }

        // '1' proven, '0' proven to be someone else, 'u' no answer.
        $status   = 'u';
        $hostname = ($this->reverseLookup)($ip);
        if (\is_string($hostname) && $hostname !== '' && $hostname !== $ip) {
            $hostname = strtolower(rtrim($hostname, '.'));
            $status   = '0';
            foreach ($verifiable[$botName] as $suffix) {
                if (!str_ends_with($hostname, $suffix)) {
                    continue;
                }
                $forward = ($this->forwardLookup)($hostname, str_contains($ip, ':'));
                if ($forward === []) {
                    $status = 'u';
                    break;
                }
                if (self::sameIp($ip, $forward)) {
                    $status = '1';
                    break;
                }
            }
        }
        $this->kv?->set($cacheKey, $status, $status === 'u' ? 600 : 86400);

        return $status !== '0';
    }

    /** @return string[] */
    public static function forwardIps(string $host, bool $v6): array
    {
        $out     = [];
        $records = @dns_get_record($host, $v6 ? DNS_AAAA : DNS_A);
        foreach (\is_array($records) ? $records : [] as $record) {
            $value = $v6 ? ($record['ipv6'] ?? '') : ($record['ip'] ?? '');
            if (\is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }
        if (!$v6 && $out === []) {
            $single = @gethostbyname($host);
            if ($single !== $host) {
                $out[] = $single;
            }
        }

        return $out;
    }

    /** @param string[] $candidates */
    public static function sameIp(string $ip, array $candidates): bool
    {
        $packed = @inet_pton($ip);
        foreach ($candidates as $candidate) {
            $other = @inet_pton((string) $candidate);
            if ($packed !== false && $packed === $other) {
                return true;
            }
        }

        return false;
    }
}

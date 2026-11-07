<?php

namespace Ux1\UxStudio\Modules\DnsManager;

use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * Propagation check: asks public resolvers (DNS over HTTPS, JSON API) what they answer for a record right now and compares it
 * with the value the administrator expects. Read only; the resolver hosts are fixed, the queried name is validated by the caller.
 */
final class Propagation
{
    private const RESOLVERS = [
        'cloudflare' => ['Cloudflare 1.1.1.1', 'https://cloudflare-dns.com/dns-query'],
        'google'     => ['Google 8.8.8.8', 'https://dns.google/resolve'],
    ];

    private const TYPES = ['A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16];

    public function __construct(private Http $http)
    {
    }

    /**
     * @param string|null $expected normalized expectation, null = only show what the resolvers answer, '' = expect NO record
     * @return array{fqdn:string,type:string,expected:?string,propagated:?bool,resolvers:list<array<string,mixed>>}
     */
    public function check(string $fqdn, string $type, ?string $expected): array
    {
        $out = [];
        foreach (self::RESOLVERS as $id => [$label, $url]) {
            $res = ['id' => $id, 'name' => $label, 'status' => 'error', 'values' => [], 'ttl' => null];
            try {
                $r = $this->http->get($url . '?' . http_build_query(['name' => $fqdn, 'type' => $type]), [
                    'headers'          => ['Accept' => 'application/dns-json'],
                    'timeout'          => 6,
                    'max_bytes'        => 65536,
                    'follow_redirects' => false,
                ]);
                $json = json_decode($r['body'], true);
                if ($r['status'] === 200 && \is_array($json)) {
                    $values = [];
                    $ttl    = null;
                    foreach ((array) ($json['Answer'] ?? []) as $a) {
                        if ((int) ($a['type'] ?? 0) !== self::TYPES[$type]) {
                            continue;
                        }
                        $values[] = self::normalize($type, (string) ($a['data'] ?? ''));
                        $ttl      = isset($a['TTL']) ? (int) $a['TTL'] : $ttl;
                    }
                    $res['values'] = array_values(array_unique($values));
                    $res['ttl']    = $ttl;
                    $res['status'] = self::verdict($res['values'], $expected);
                }
            } catch (\Throwable $e) {
                $res['status'] = 'error';
            }
            $out[] = $res;
        }
        $known      = array_filter($out, static fn (array $r): bool => $r['status'] !== 'error');
        $propagated = ($expected !== null && $known !== []) ? !array_filter($known, static fn (array $r): bool => $r['status'] !== 'match') : null;

        return ['fqdn' => $fqdn, 'type' => $type, 'expected' => $expected, 'propagated' => $propagated, 'resolvers' => $out];
    }

    /** @param list<string> $values */
    private static function verdict(array $values, ?string $expected): string
    {
        if ($expected === null) {
            return $values === [] ? 'empty' : 'info';
        }
        if ($expected === '') {
            return $values === [] ? 'match' : 'mismatch';
        }
        if ($values === []) {
            return 'empty';
        }

        return \in_array($expected, $values, true) ? 'match' : 'mismatch';
    }

    /** One comparable form: lower case host names without the trailing dot, TXT chunks joined and unquoted. */
    public static function normalize(string $type, string $value): string
    {
        $v = trim($value);
        if ($type === 'TXT') {
            // "chunk one" "chunk two" -> chunk onechunk two (resolvers split long strings into 255 byte chunks)
            if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $v, $m) && $m[1] !== []) {
                return stripcslashes(implode('', $m[1]));
            }

            return trim($v, '"');
        }
        if ($type === 'MX') {
            $parts = preg_split('/\s+/', $v, 2) ?: [];

            return \count($parts) === 2 ? ((int) $parts[0]) . ' ' . strtolower(rtrim($parts[1], '.')) : strtolower($v);
        }
        if ($type === 'CNAME') {
            return strtolower(rtrim($v, '.'));
        }

        return strtolower($v);
    }
}

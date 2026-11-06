<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Language\Text;
use Joomla\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Outbound HTTP for modules: Joomla HTTP client with a timeout, a User-Agent and an SSRF guard.
 * Redirects are followed manually (max 3 hops) and every hop is validated again.
 * Limitation: the host is resolved by the guard and again by cURL (DNS rebinding window); do not use for
 * URLs that must be trusted beyond this check.
 */
final class Http
{
    public const USER_AGENT = 'UXStudio-Joomla/1.0 (+https://ux1.cz)';

    /**
     * @param array{headers?:array<string,string>,body?:array|string,timeout?:int,allow_private?:bool,json?:bool} $options
     *        body array => form-encoded (or JSON when json=true). allow_private=true skips the guard (ONLY for fixed trusted URLs).
     * @return array{status:int,headers:array<string,string>,body:string}
     * @throws ApiException when the URL is rejected by the SSRF guard
     * @throws \RuntimeException on transport errors
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $method  = strtoupper($method);
        $timeout = (int) ($options['timeout'] ?? 10);
        $headers = $options['headers'] ?? [];
        $body    = $options['body'] ?? null;
        $allow   = !empty($options['allow_private']);

        if (\is_array($body)) {
            if (!empty($options['json'])) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers += ['Content-Type' => 'application/json'];
            } else {
                $body = http_build_query($body);
            }
        }

        $client = (new HttpFactory())->getHttp(['timeout' => $timeout, 'userAgent' => self::USER_AGENT, 'follow_location' => false]);

        for ($hop = 0; $hop <= 3; $hop++) {
            if (!$allow) {
                self::assertPublicUrl($url);
            }
            $response = match ($method) {
                'GET'    => $client->get($url, $headers, $timeout),
                'HEAD'   => $client->head($url, $headers, $timeout),
                'DELETE' => $client->delete($url, $headers, $timeout),
                'PUT'    => $client->put($url, (string) $body, $headers, $timeout),
                'PATCH'  => $client->patch($url, (string) $body, $headers, $timeout),
                default  => $client->post($url, (string) $body, $headers, $timeout),
            };

            $status = $response->getStatusCode();
            $flat   = [];
            foreach ($response->getHeaders() as $name => $values) {
                $flat[strtolower((string) $name)] = implode(', ', (array) $values);
            }

            if ($status >= 300 && $status < 400 && !empty($flat['location']) && $hop < 3) {
                $url    = self::resolve($url, $flat['location']);
                $method = \in_array($status, [301, 302, 303], true) ? 'GET' : $method;
                $body   = $method === 'GET' ? null : $body;
                continue;
            }

            return ['status' => $status, 'headers' => $flat, 'body' => (string) $response->getBody()];
        }

        throw new \RuntimeException('Too many redirects.');
    }

    public function get(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, array|string $body, array $options = []): array
    {
        return $this->request('POST', $url, ['body' => $body] + $options);
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . rtrim(\dirname($p['path'] ?? '/'), '/') . '/' . $location;
    }

    /**
     * Rejects non-http(s) schemes, credentials in the URL and hosts that resolve to private, loopback,
     * link-local or otherwise reserved addresses.
     *
     * @throws ApiException
     */
    public static function assertPublicUrl(string $url): void
    {
        $fail = static fn () => new ApiException(Text::_('UXS_CORE_ERR_SSRF'), 422, 'ssrf_blocked');

        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !isset($parts['scheme']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw $fail();
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw $fail();
        }

        $host = trim($parts['host'], '[]');
        if (strcasecmp($host, 'localhost') === 0 || str_ends_with(strtolower($host), '.localhost')) {
            throw $fail();
        }

        foreach (self::resolveHost($host) as $ip) {
            if (!self::isPublicIp($ip)) {
                throw $fail();
            }
        }
    }

    /** @return string[] */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        // Numeric tricks (0x7f.1, 2130706433, 017700000001) are not valid hostnames for our purposes.
        if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+))*$/i', $host)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_SSRF'), 422, 'ssrf_blocked');
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
        if (!$ips) {
            $v4 = @gethostbynamel($host);
            $ips = $v4 ?: [];
        }
        if (!$ips) {
            throw new ApiException(Text::_('UXS_CORE_ERR_SSRF'), 422, 'ssrf_blocked');
        }

        return $ips;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) => judge the embedded IPv4 address.
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if (!$public) {
            return false;
        }
        // Ranges PHP does not flag: CGNAT 100.64/10, IPv6 unique-local fc00::/7 and link-local fe80::/10 are covered above on 8.1+,
        // but be explicit for CGNAT and benchmarking ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach (['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24'] as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        }

        return true;
    }
}

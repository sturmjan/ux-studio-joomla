<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Language\Text;
use Joomla\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Outbound HTTP for modules: Joomla HTTP client with a timeout, a User-Agent and an SSRF guard.
 *
 * The guard resolves the host itself, rejects non-public addresses (explicit IPv4 / IPv6 CIDR lists, independent of the
 * PHP version) and PINS the verified address for the connection (CURLOPT_RESOLVE), so a DNS answer that changes between
 * the check and the connection (DNS rebinding) cannot redirect the request. Redirects are followed manually (max 3 hops)
 * and every hop is validated and pinned again. Only http(s) is allowed on every hop and the response size is capped.
 */
final class Http
{
    public const USER_AGENT = 'UXStudio-Joomla/1.0 (+https://ux1.cz)';

    /** Default cap of the response body in bytes. */
    public const MAX_BYTES = 5242880;

    /** Never reachable from the outside: IPv4 CIDRs. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** Never reachable from the outside: IPv6 CIDRs. */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * @param array{headers?:array<string,string>,body?:array|string,timeout?:int,allow_private?:bool,json?:bool,max_bytes?:int,follow_redirects?:bool,max_redirects?:int} $options
     *        body array => form-encoded (or JSON when json=true). allow_private=true skips the guard (ONLY for fixed trusted URLs);
     *        a redirect to a different host is then refused (ApiException redirect_blocked), the trusted host cannot be used to bounce
     *        the request elsewhere. follow_redirects (default true = up to max_redirects, default 3, hops) false returns the 3xx answer itself.
     * @return array{status:int,headers:array<string,string>,body:string,url:string,redirected:bool,redirect_chain:string[],redirect_to:?string}
     *         url = the address the answer came from; redirect_chain = the addresses that were left by a redirect;
     *         redirect_to = absolute Location of a 3xx answer that was NOT followed (follow_redirects=false or hop limit), else null.
     * @throws ApiException when the URL is rejected by the SSRF guard
     * @throws \RuntimeException on transport errors
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $method   = strtoupper($method);
        $timeout  = (int) ($options['timeout'] ?? 10);
        $headers  = $options['headers'] ?? [];
        $body     = $options['body'] ?? null;
        $allow    = !empty($options['allow_private']);
        $maxBytes = max(1024, (int) ($options['max_bytes'] ?? self::MAX_BYTES));
        $follow   = !\array_key_exists('follow_redirects', $options) || !empty($options['follow_redirects']);
        $maxHops  = max(0, min(10, (int) ($options['max_redirects'] ?? 3)));
        $origin   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $chain    = [];

        if (!\function_exists('curl_init')) {
            // Without cURL the verified address cannot be pinned: refuse instead of running an unguarded request.
            throw new \RuntimeException('The PHP cURL extension is required.');
        }

        if (\is_array($body)) {
            if (!empty($options['json'])) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers += ['Content-Type' => 'application/json'];
            } else {
                $body = http_build_query($body);
            }
        }

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $curl = [
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE     => $maxBytes,
                CURLOPT_NOPROGRESS      => false,
                // Abort once more than $maxBytes arrived (Content-Length may be absent or a lie).
                CURLOPT_PROGRESSFUNCTION => static fn ($ch, $dlTotal, $dlNow) => $dlNow > $maxBytes ? 1 : 0,
            ];
            if (!$allow) {
                $pin = self::assertPublicUrl($url);
                if ($pin !== null) {
                    $curl[CURLOPT_RESOLVE] = [$pin];
                }
            }

            $client = (new HttpFactory())->getHttp([
                'timeout'         => $timeout,
                'userAgent'       => self::USER_AGENT,
                'follow_location' => false,
                'transport.curl'  => $curl,
            ], 'curl');

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

            $location = $status >= 300 && $status < 400 && !empty($flat['location']) ? self::resolve($url, $flat['location']) : null;
            if ($location !== null && $follow && $hop < $maxHops) {
                if ($allow && strtolower((string) parse_url($location, PHP_URL_HOST)) !== $origin) {
                    throw new ApiException(Text::_('UXS_CORE_ERR_REDIRECT'), 422, 'redirect_blocked');
                }
                $chain[] = $url;
                $url     = $location;
                $method  = \in_array($status, [301, 302, 303], true) ? 'GET' : $method;
                $body    = $method === 'GET' ? null : $body;
                continue;
            }

            return [
                'status'         => $status,
                'headers'        => $flat,
                'body'           => (string) $response->getBody(),
                'url'            => $url,
                'redirected'     => $chain !== [],
                'redirect_chain' => $chain,
                'redirect_to'    => $location,
            ];
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
     * link-local or otherwise reserved addresses (ALL resolved addresses must be public).
     *
     * @return string|null "host:port:ip" line for CURLOPT_RESOLVE, null when the host is an IP literal (nothing to pin)
     * @throws ApiException
     */
    public static function assertPublicUrl(string $url): ?string
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

        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));
        $ips  = self::resolveHost($host);
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw $fail();
            }
        }

        return filter_var($host, FILTER_VALIDATE_IP) ? null : $host . ':' . $port . ':' . $ips[0];
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

    /** True for a globally routable unicast address. Uses explicit CIDR lists, not FILTER_FLAG_NO_*_RANGE. */
    public static function isPublicIp(string $ip): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : false;
        if ($packed === false) {
            return false;
        }
        $v6 = \strlen($packed) === 16;

        foreach ($v6 ? self::BLOCKED_V6 : self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked    = (string) inet_pton($net);
        if (\strlen($netPacked) !== \strlen($packedIp)) {
            return false;
        }
        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($packedIp[$bytes]) & $mask) === (\ord($netPacked[$bytes]) & $mask);
    }
}

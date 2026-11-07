<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Language\Text;
use Joomla\Http\HttpFactory;
use Ux1\UxStudio\Core\Http;
use Ux1\UxStudio\Core\SafeRegex;

\defined('_JEXEC') or die;

/**
 * URL tester + rule audit. Both answer the question an SEO check asks: does the old URL reach a live page in exactly
 * one permanent hop?
 */
final class Tester
{
    /** Max hops followed by test(). */
    private const MAX_HOPS = 10;

    /** Max rules scanned by one audit run. */
    private const AUDIT_MAX = 5000;

    /**
     * Resolves a URL through the rules (no HTTP), optionally also follows it over HTTP and reports every hop.
     *
     * @return array<string,mixed>
     */
    public static function test(string $url, bool $live = false): array
    {
        $parsed = Url::parseSource($url);
        $path   = $parsed['path'];
        $pathCs = $parsed['path_cs'];
        $query  = $parsed['query'];
        $steps  = [];
        $seen   = [];
        $loop   = false;

        for ($hop = 0; $hop < self::MAX_HOPS && $path !== ''; $hop++) {
            $key = Url::key($path, $query);
            if (isset($seen[$key])) {
                $loop = true;
                break;
            }
            $seen[$key] = true;

            $match = Repository::match($path, $query, $pathCs);
            if (!$match) {
                break;
            }
            $steps[] = [
                'from'    => Url::absolute($key),
                'code'    => (int) $match['rule']['code'],
                'to'      => $match['target'],
                'rule_id' => (int) $match['rule']['id'],
            ];
            $nextKey = Url::targetKey($match['target']);
            if ($match['target'] === '' || $nextKey === '') {
                break;
            }
            $next   = explode('?', $nextKey, 2);
            $nextCs = explode('?', Url::targetKey($match['target'], true), 2);
            $path   = $next[0];
            $pathCs = $nextCs[0];
            $query  = $next[1] ?? '';
        }

        $last   = $steps ? $steps[\count($steps) - 1] : null;
        $issues = [];
        if ($loop) {
            $issues[] = 'loop';
        }
        if (\count($steps) > 1) {
            $issues[] = 'chain';
        }
        if ($last && \in_array($last['code'], [302, 307], true)) {
            $issues[] = 'temporary';
        }

        $result = [
            'input'      => $url,
            'tested_url' => Url::absolute(Url::key($parsed['path'], $parsed['query'])),
            'matched'    => (bool) $steps,
            'hops'       => \count($steps),
            'final_url'  => $last ? $last['to'] : null,
            'final_code' => $last ? $last['code'] : null,
            'loop'       => $loop,
            'steps'      => $steps,
            'issues'     => $issues,
        ];
        if ($live) {
            $result['live'] = self::follow($result['tested_url']);
            if (!$result['live']['ok']) {
                $result['issues'][] = 'live_check_failed';
            }
        }

        return $result;
    }

    /**
     * Follows a URL over HTTP hop by hop (no automatic redirects). Addresses of this site are requested directly (the
     * administrator typed them, they are the point of the test); anything else goes through the SSRF-guarded client.
     *
     * @return array{ok:bool,final_status:int,hops:int,chain:array<int,array{url:string,status:int,location:string}>,error?:string}
     */
    public static function follow(string $url): array
    {
        $chain = [];
        $seen  = [];
        for ($i = 0; $i <= self::MAX_HOPS; $i++) {
            if (isset($seen[$url])) {
                return ['ok' => false, 'final_status' => 0, 'hops' => \count($chain), 'chain' => $chain, 'error' => 'loop'];
            }
            $seen[$url] = true;
            try {
                if (Url::isSameOrigin($url)) {
                    $client   = (new HttpFactory())->getHttp(['timeout' => 10, 'userAgent' => 'UX Studio Redirect Checker', 'follow_location' => false], 'curl');
                    $response = $client->get($url, [], 10);
                    $status   = $response->getStatusCode();
                    $location = (string) ($response->getHeaderLine('Location'));
                } else {
                    // The wrapper follows up to three redirects by itself: report only the final answer.
                    $response = (new Http())->get($url, ['timeout' => 10, 'max_bytes' => 65536]);
                    $chain[]  = ['url' => $url, 'status' => $response['status'], 'location' => ''];

                    return ['ok' => $response['status'] === 200 && \count($chain) <= 1, 'final_status' => $response['status'], 'hops' => 0, 'chain' => $chain];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'final_status' => 0, 'hops' => \count($chain), 'chain' => $chain, 'error' => $e->getMessage()];
            }
            $chain[] = ['url' => $url, 'status' => $status, 'location' => $location];
            if ($status < 300 || $status >= 400 || $location === '') {
                $hops = \count($chain) - 1;

                return [
                    'ok'           => ($status === 200 && $hops <= 1) || ($status === 410 && $hops === 0),
                    'final_status' => $status,
                    'hops'         => $hops,
                    'chain'        => $chain,
                ];
            }
            $url = self::absoluteLocation($location, $url);
        }

        return ['ok' => false, 'final_status' => 0, 'hops' => \count($chain), 'chain' => $chain, 'error' => 'too_many_redirects'];
    }

    private static function absoluteLocation(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts  = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin . $dir . '/' . $location;
    }

    /**
     * Audits every enabled rule.
     *
     * @param bool $live      HTTP-check targets (slow; capped by $liveLimit)
     * @param bool $fix       re-save chained rules so they point straight at the final target
     * @return array{summary:array<string,int>,issues:array<int,array<string,mixed>>}
     */
    public static function audit(bool $live = false, int $liveLimit = 50, bool $fix = false): array
    {
        $db   = Repository::db();
        $rows = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(Repository::TABLE))->where($db->quoteName('enabled') . ' = 1')
                ->order($db->quoteName('hits') . ' DESC, ' . $db->quoteName('id') . ' ASC'),
            0,
            self::AUDIT_MAX
        )->loadAssocList() ?: [];

        $issues  = [];
        $toHome  = 0;
        $checked = 0;
        $fixed   = 0;
        $month   = \Joomla\CMS\Factory::getDate('-30 days')->toSql();

        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $code   = (int) $row['code'];
            $source = (string) $row['source'];
            $add    = static function (string $severity, string $issue, string $message) use (&$issues, $id, $source): void {
                $issues[] = ['severity' => $severity, 'code' => $issue, 'rule_id' => $id, 'source' => $source, 'message' => $message];
            };

            if ($row['match_type'] === 'regex') {
                if (!SafeRegex::compiles(SafeRegex::delimit((string) $row['source_key']))) {
                    $add('error', 'invalid_regex', Text::_('UXS_REDIRECTS_AUDIT_INVALID_REGEX'));
                }
                continue;
            }

            // A case sensitive rule is reached only by its letters as typed.
            $probe = (string) ($row['source_cs'] ?? '') !== '' ? (string) $row['source_cs'] : (string) $row['source_key'];
            $test  = self::test($probe);
            if ($test['loop']) {
                $add('error', 'loop', Text::_('UXS_REDIRECTS_AUDIT_LOOP'));
            } elseif ($test['hops'] > 1) {
                if ($fix) {
                    $result = Repository::save(['id' => $id, 'target' => (string) $row['target']]);
                    if (\in_array($result['status'], ['updated', 'unchanged'], true) && self::test($probe)['hops'] <= 1) {
                        $fixed++;
                        continue;
                    }
                }
                $add('warning', 'chain', Text::sprintf('UXS_REDIRECTS_AUDIT_CHAIN', $test['hops']));
            }

            if ($code !== 410 && Url::targetKey((string) $row['target']) === '/') {
                $toHome++;
            }
            if (\in_array($code, [302, 307], true) && (string) $row['created_at'] < $month) {
                $add('warning', 'temporary_long', Text::_('UXS_REDIRECTS_AUDIT_TEMPORARY_LONG'));
            }

            if ($live && $code !== 410 && $checked < $liveLimit) {
                $checked++;
                $check = self::follow(Repository::resolveTarget($row));
                if ($check['final_status'] !== 200 || $check['hops'] > 0) {
                    $add('error', 'target_broken', Text::sprintf('UXS_REDIRECTS_AUDIT_TARGET_BROKEN', $check['final_status'], $check['hops']));
                }
            }
        }

        $total = \count($rows);
        if ($toHome >= 5 && $toHome > $total * 0.1) {
            $issues[] = ['severity' => 'warning', 'code' => 'homepage_mass', 'rule_id' => null, 'source' => '', 'message' => Text::sprintf('UXS_REDIRECTS_AUDIT_HOMEPAGE_MASS', $toHome)];
        }

        return [
            'summary' => [
                'rules'        => $total,
                'errors'       => \count(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'error')),
                'warnings'     => \count(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'warning')),
                'live_checked' => $checked,
                'fixed'        => $fixed,
            ],
            'issues'  => $issues,
        ];
    }
}

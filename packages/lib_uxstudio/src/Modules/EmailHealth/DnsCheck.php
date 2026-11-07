<?php

namespace Ux1\UxStudio\Modules\EmailHealth;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * DNS side of e-mail deliverability for a sender domain: MX, SPF, DKIM and DMARC. Only DNS queries are made (nothing is
 * connected to), so a user supplied domain needs no SSRF guard beyond a syntax check.
 */
final class DnsCheck
{
    /** DKIM selectors tried when the admin does not know theirs (DKIM selectors cannot be listed in DNS). */
    public const DEFAULT_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'k3', 's1', 's2', 'mail', 'dkim', 'smtp', 'brevo1', 'brevo2', 'mandrill', 'mxvault', 'zoho', 'protonmail', 'everlytickey1', 'cm'];

    private const SEVERITY = ['ok' => 0, 'info' => 0, 'warn' => 1, 'bad' => 2];

    /** @param string[] $selectors @param string[] $expectedIncludes substrings the SPF record should authorise (for example "brevo.com") */
    public function __construct(private array $selectors = [], private array $expectedIncludes = [])
    {
    }

    public static function validDomain(string $domain): bool
    {
        return \strlen($domain) <= 253 && (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain);
    }

    /**
     * @return array{domain:string,checked_at:string,overall:string,checks:array<int,array<string,mixed>>}
     */
    public function run(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $checks = [$this->mx($domain), $this->spf($domain), $this->dkim($domain), $this->dmarc($domain)];

        $worst = 'ok';
        foreach ($checks as $c) {
            if (self::SEVERITY[$c['status']] > self::SEVERITY[$worst]) {
                $worst = $c['status'];
            }
        }

        return ['domain' => $domain, 'checked_at' => Factory::getDate()->toSql(), 'overall' => $worst, 'checks' => $checks];
    }

    /** @return string[] TXT strings of a name (chunks of one record joined) */
    private function txt(string $name): array
    {
        $out = [];
        foreach (@dns_get_record($name, DNS_TXT) ?: [] as $rec) {
            $out[] = isset($rec['entries']) && \is_array($rec['entries']) ? implode('', $rec['entries']) : (string) ($rec['txt'] ?? '');
        }

        return $out;
    }

    /**
     * Language-neutral message: the check can run in the scheduler (CLI, site language) and be shown to an admin in
     * another language, so only the key and arguments are stored and translated in localize().
     *
     * @return array{key:string,args:array<int,string|int>}
     */
    private static function t(string $key, string|int ...$args): array
    {
        return ['key' => $key, 'args' => array_values($args)];
    }

    /**
     * Translates the stored messages of a DNS result to the current language (older rows hold plain strings).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function localize(array $data): array
    {
        $tr = static function (mixed $m): string {
            if (!\is_array($m) || !isset($m['key'])) {
                return (string) $m;
            }
            $args = (array) ($m['args'] ?? []);

            return $args ? Text::sprintf((string) $m['key'], ...$args) : Text::_((string) $m['key']);
        };
        foreach ($data['checks'] ?? [] as $i => $c) {
            $data['checks'][$i]['summary'] = $tr($c['summary'] ?? '');
            $data['checks'][$i]['notes']   = array_map($tr, (array) ($c['notes'] ?? []));
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function result(string $id, string $status, array $summary, array $records = [], array $notes = []): array
    {
        return ['id' => $id, 'status' => $status, 'summary' => $summary, 'records' => array_values($records), 'notes' => array_values($notes)];
    }

    /* ---- MX ---- */

    private function mx(string $domain): array
    {
        $mx = @dns_get_record($domain, DNS_MX) ?: [];
        usort($mx, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $records = array_map(static fn (array $r): string => ($r['pri'] ?? 0) . ' ' . ($r['target'] ?? ''), $mx);
        if ($mx) {
            return $this->result('mx', 'ok', self::t('UXS_EMAIL_HEALTH_MX_OK', \count($mx)), $records);
        }
        if (@dns_get_record($domain, DNS_A | DNS_AAAA)) {
            return $this->result('mx', 'warn', self::t('UXS_EMAIL_HEALTH_MX_NONE_A'));
        }

        return $this->result('mx', 'bad', self::t('UXS_EMAIL_HEALTH_MX_NONE'));
    }

    /* ---- SPF ---- */

    private function spf(string $domain): array
    {
        $records = array_values(array_filter($this->txt($domain), static fn (string $t): bool => (bool) preg_match('/^\s*v=spf1(\s|$)/i', $t)));
        if (!$records) {
            return $this->result('spf', 'bad', self::t('UXS_EMAIL_HEALTH_SPF_NONE'));
        }
        if (\count($records) > 1) {
            return $this->result('spf', 'bad', self::t('UXS_EMAIL_HEALTH_SPF_MULTI', \count($records)), $records);
        }

        $record = $records[0];
        $status = 'ok';
        $notes  = [];
        $worse  = static function (string $new) use (&$status): void {
            if (self::SEVERITY[$new] > self::SEVERITY[$status]) {
                $status = $new;
            }
        };

        $lookups = 0;
        $seen    = [strtolower($domain)];
        $haystack = strtolower($record);
        $this->countLookups($record, 0, $lookups, $seen, $haystack);

        if (!preg_match('/\s([+\-~?]?)all(\s|$)/i', $record . ' ', $m)) {
            if (!preg_match('/\bredirect=/i', $record)) {
                $worse('warn');
                $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_NO_ALL');
            }
        } elseif (\in_array($m[1], ['', '+', '?'], true)) {
            $worse('warn');
            $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_ALL_OPEN');
        } elseif ($m[1] === '~') {
            $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_SOFTFAIL');
        }
        if ($lookups > 10) {
            $worse('bad');
            $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_LOOKUPS', $lookups);
        }
        if (preg_match('/\sptr(\s|:|$)/i', $record)) {
            $worse('warn');
            $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_PTR');
        }
        foreach ($this->expectedIncludes as $needle) {
            if ($needle !== '' && !str_contains($haystack, strtolower($needle))) {
                $worse('warn');
                $notes[] = self::t('UXS_EMAIL_HEALTH_SPF_MISSING_PROVIDER', $needle);
            }
        }

        return $this->result('spf', $status, self::t($status === 'ok' ? 'UXS_EMAIL_HEALTH_SPF_OK' : 'UXS_EMAIL_HEALTH_SPF_ISSUES'), [$record], $notes);
    }

    /**
     * Counts the DNS-querying mechanisms of an SPF record, following include: and redirect= (RFC 7208 limit: 10).
     * Also collects the text of the included records into $haystack.
     *
     * @param string[] $seen
     */
    private function countLookups(string $record, int $depth, int &$lookups, array &$seen, string &$haystack): void
    {
        foreach (preg_split('/\s+/', trim($record)) ?: [] as $term) {
            $term = ltrim($term, '+-~?');
            if (preg_match('/^(a|mx|ptr|exists)(:|\/|$)/i', $term)) {
                $lookups++;
            } elseif (preg_match('/^(include:|redirect=)(.+)$/i', $term, $m)) {
                $lookups++;
                $target = strtolower($m[2]);
                if ($depth >= 6 || \in_array($target, $seen, true) || $lookups > 20 || !self::validDomain($target)) {
                    continue;
                }
                $seen[] = $target;
                foreach ($this->txt($target) as $t) {
                    if (preg_match('/^\s*v=spf1(\s|$)/i', $t)) {
                        $haystack .= ' ' . strtolower($t);
                        $this->countLookups($t, $depth + 1, $lookups, $seen, $haystack);
                    }
                }
            }
        }
    }

    /* ---- DKIM ---- */

    private function dkim(string $domain): array
    {
        $found   = [];
        $records = [];
        $notes   = [];
        $status  = 'ok';
        $tried   = array_slice(array_values(array_unique(array_merge($this->selectors, self::DEFAULT_SELECTORS))), 0, 30);

        foreach ($tried as $sel) {
            $name = $sel . '._domainkey.' . $domain;
            $txt  = array_values(array_filter($this->txt($name), static fn (string $t): bool => (bool) preg_match('/(^|;)\s*(v=DKIM1|p=)/i', $t)));
            $cname = !$txt ? (@dns_get_record($name, DNS_CNAME) ?: []) : [];
            if (!$txt && !$cname) {
                continue;
            }
            $found[] = $sel;
            if ($txt) {
                $records[] = $sel . ': ' . mb_substr($txt[0], 0, 160) . (mb_strlen($txt[0]) > 160 ? '…' : '');
                if (preg_match('/p=\s*(;|$)/', $txt[0])) {
                    $status  = 'warn';
                    $notes[] = self::t('UXS_EMAIL_HEALTH_DKIM_REVOKED', $sel);
                } elseif (preg_match('/p=([A-Za-z0-9+\/=\s]+)/', $txt[0], $m) && \strlen(preg_replace('/\s+/', '', $m[1])) < 250 && !str_contains(strtolower($txt[0]), 'k=ed25519')) {
                    $status  = 'warn';
                    $notes[] = self::t('UXS_EMAIL_HEALTH_DKIM_WEAK', $sel);
                }
            } else {
                $records[] = $sel . ': CNAME ' . ($cname[0]['target'] ?? '');
            }
        }

        if (!$found) {
            return $this->result('dkim', 'warn', self::t('UXS_EMAIL_HEALTH_DKIM_NONE', implode(', ', \array_slice($tried, 0, 8)) . '…'));
        }

        return $this->result('dkim', $status, self::t('UXS_EMAIL_HEALTH_DKIM_OK', implode(', ', $found)), $records, $notes);
    }

    /* ---- DMARC ---- */

    private function dmarc(string $domain): array
    {
        $inherited = '';
        $record    = $this->dmarcRecord($domain);
        if ($record === '') {
            $org = self::organizationalDomain($domain);
            if ($org !== $domain) {
                $record = $this->dmarcRecord($org);
                if ($record !== '') {
                    $inherited = $org;
                }
            }
        }
        if ($record === '') {
            return $this->result('dmarc', 'bad', self::t('UXS_EMAIL_HEALTH_DMARC_NONE'));
        }

        $tags = [];
        foreach (explode(';', $record) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = array_map('trim', explode('=', $part, 2));
                $tags[strtolower($k)] = $v;
            }
        }
        $policy = strtolower($tags['p'] ?? '');
        $status = 'ok';
        $notes  = [];
        if ($inherited !== '') {
            $notes[] = self::t('UXS_EMAIL_HEALTH_DMARC_INHERITED', $inherited);
        }
        if (!\in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $status  = 'bad';
            $notes[] = self::t('UXS_EMAIL_HEALTH_DMARC_INVALID');
        } elseif ($policy === 'none') {
            $status  = 'warn';
            $notes[] = self::t('UXS_EMAIL_HEALTH_DMARC_P_NONE');
        }
        if (empty($tags['rua'])) {
            $status  = $status === 'ok' ? 'warn' : $status;
            $notes[] = self::t('UXS_EMAIL_HEALTH_DMARC_NO_RUA');
        }
        if (isset($tags['pct']) && (int) $tags['pct'] < 100) {
            $notes[] = self::t('UXS_EMAIL_HEALTH_DMARC_PCT', (int) $tags['pct']);
        }

        return $this->result('dmarc', $status, self::t('UXS_EMAIL_HEALTH_DMARC_POLICY', $policy !== '' ? $policy : '?'), [$record], $notes);
    }

    private function dmarcRecord(string $domain): string
    {
        foreach ($this->txt('_dmarc.' . $domain) as $t) {
            if (preg_match('/^\s*v=DMARC1\s*(;|$)/i', $t)) {
                return trim($t);
            }
        }

        return '';
    }

    /** Registrable domain without a public suffix list: last two labels, three for common second-level suffixes (co.uk, com.au ...). */
    public static function organizationalDomain(string $domain): string
    {
        $labels = explode('.', $domain);
        $n      = \count($labels);
        if ($n <= 2) {
            return $domain;
        }
        $take = $n >= 3 && \strlen($labels[$n - 1]) === 2 && \in_array($labels[$n - 2], ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu', 'ltd', 'plc'], true) ? 3 : 2;

        return implode('.', \array_slice($labels, -$take));
    }
}

<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Failed-login counter and lock-out bookkeeping (per client IP) plus the login event log.
 * Pure data layer: the decisions (when to check, what to answer) live in Module.
 */
final class LoginGuard
{
    private const LOCKS = '#__uxstudio_secopt_lockouts';
    private const LOG   = '#__uxstudio_secopt_login_log';

    public function __construct(private DatabaseInterface $db)
    {
    }

    private static function now(): string
    {
        return Factory::getDate()->toSql();
    }

    /** Seconds the address is still locked out, 0 when it may try. */
    public function lockedFor(string $ip): int
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName('locked_until'))->from($db->quoteName(self::LOCKS))
                ->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip)
        )->loadResult();
        if (!$row) {
            return 0;
        }

        return max(0, (int) strtotime($row . ' UTC') - time());
    }

    /**
     * Records one failed login. Returns the lock-out that this failure triggered (null = below the limit).
     *
     * @param array{max:int,window_minutes:int,minutes:int,progressive:bool,multiplier:int,max_hours:int} $policy
     * @return array{minutes:int,strikes:int}|null
     */
    public function fail(string $ip, string $username, array $policy): ?array
    {
        $db      = $this->db;
        $nowTs   = time();
        $now     = self::now();
        $window  = max(1, $policy['window_minutes']) * 60;
        $row     = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::LOCKS))->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip)
        )->loadAssoc();

        $fails  = 1;
        $strikes = (int) ($row['strikes'] ?? 0);
        $first  = $now;
        if ($row) {
            $started = (int) strtotime($row['window_start'] . ' UTC');
            if ($nowTs - $started <= $window) {
                $fails = (int) $row['fails'] + 1;
                $first = (string) $row['window_start'];
            }
            // Strikes fade after 24 hours without a lock-out.
            if ($row['last_strike'] && $nowTs - (int) strtotime($row['last_strike'] . ' UTC') > 86400) {
                $strikes = 0;
            }
        }

        $lock = null;
        $lockedUntil = $row['locked_until'] ?? null;
        $lastStrike  = $row['last_strike'] ?? null;
        if ($fails >= max(1, $policy['max'])) {
            $strikes++;
            $minutes = $policy['minutes'];
            if ($policy['progressive'] && $strikes > 1) {
                $minutes = (int) min($minutes * ($policy['multiplier'] ** ($strikes - 1)), $policy['max_hours'] * 60);
            }
            $minutes     = max(1, $minutes);
            $lockedUntil = gmdate('Y-m-d H:i:s', $nowTs + $minutes * 60);
            $lastStrike  = $now;
            $fails       = 0;
            $first       = $now;
            $lock        = ['minutes' => $minutes, 'strikes' => $strikes];
        }

        $upsert = $db->createQuery()->setQuery(
            'INSERT INTO ' . $db->quoteName(self::LOCKS) . ' (' . implode(', ', $db->quoteName(['ip', 'fails', 'window_start', 'locked_until', 'strikes', 'last_strike', 'updated_at'])) . ') '
            . 'VALUES (:ip, :f, :w, :lu, :s, :ls, :u) ON DUPLICATE KEY UPDATE '
            . $db->quoteName('fails') . ' = VALUES(' . $db->quoteName('fails') . '), ' . $db->quoteName('window_start') . ' = VALUES(' . $db->quoteName('window_start') . '), '
            . $db->quoteName('locked_until') . ' = VALUES(' . $db->quoteName('locked_until') . '), ' . $db->quoteName('strikes') . ' = VALUES(' . $db->quoteName('strikes') . '), '
            . $db->quoteName('last_strike') . ' = VALUES(' . $db->quoteName('last_strike') . '), ' . $db->quoteName('updated_at') . ' = VALUES(' . $db->quoteName('updated_at') . ')'
        )->bind(':ip', $ip)->bind(':f', $fails, ParameterType::INTEGER)->bind(':w', $first)->bind(':lu', $lockedUntil)->bind(':s', $strikes, ParameterType::INTEGER)
            ->bind(':ls', $lastStrike)->bind(':u', $now);
        $db->setQuery($upsert)->execute();

        $this->log($lock ? 'lockout' : 'fail', $ip, $username);

        return $lock;
    }

    /** Successful login: the failure counter restarts (lock-out strikes are kept until they fade). */
    public function succeed(string $ip, string $username): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::LOCKS))->set($db->quoteName('fails') . ' = 0')
                ->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip)
        )->execute();
        $this->log('success', $ip, $username);
    }

    public function strikes(string $ip): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            $db->createQuery()->select($db->quoteName('strikes'))->from($db->quoteName(self::LOCKS))->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip)
        )->loadResult();
    }

    /** kind: fail | lockout | blocked | captcha | success | secret */
    public function log(string $kind, string $ip, string $username = ''): void
    {
        try {
            $db       = $this->db;
            $now      = self::now();
            $username = mb_substr($username, 0, 150);
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::LOG))->columns($db->quoteName(['created', 'kind', 'ip', 'username']))->values(':c, :k, :ip, :u')
                    ->bind(':c', $now)->bind(':k', $kind)->bind(':ip', $ip)->bind(':u', $username)
            )->execute();
        } catch (\Throwable $e) {
            // The log is informational: never break a login page because of it.
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100, string $kind = ''): array
    {
        $db    = $this->db;
        $query = $db->createQuery()->select('*')->from($db->quoteName(self::LOG))->order($db->quoteName('id') . ' DESC');
        if ($kind !== '') {
            $query->where($db->quoteName('kind') . ' = :k')->bind(':k', $kind);
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'created' => $r['created'], 'kind' => $r['kind'], 'ip' => $r['ip'], 'username' => $r['username'],
        ], $db->setQuery($query, 0, max(1, min(500, $limit)))->loadAssocList() ?: []);
    }

    /** @return array{fail:int,lockout:int,blocked:int,captcha:int,success:int,secret:int} counts in the last N hours */
    public function stats(int $hours = 24): array
    {
        $db    = $this->db;
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $rows  = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('kind'), 'COUNT(*) AS n'])->from($db->quoteName(self::LOG))
                ->where($db->quoteName('created') . ' >= :s')->group($db->quoteName('kind'))->bind(':s', $since)
        )->loadAssocList('kind', 'n') ?: [];
        $out = ['fail' => 0, 'lockout' => 0, 'blocked' => 0, 'captcha' => 0, 'success' => 0, 'secret' => 0];
        foreach ($rows as $kind => $n) {
            if (isset($out[$kind])) {
                $out[$kind] = (int) $n;
            }
        }

        return $out;
    }

    /** Addresses that are locked out right now. @return array<int,array<string,mixed>> */
    public function activeLocks(): array
    {
        $db  = $this->db;
        $now = self::now();

        return array_map(static fn (array $r): array => [
            'ip' => $r['ip'], 'locked_until' => $r['locked_until'], 'strikes' => (int) $r['strikes'],
        ], $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::LOCKS))->where($db->quoteName('locked_until') . ' > :n')->order($db->quoteName('locked_until') . ' DESC')->bind(':n', $now),
            0,
            100
        )->loadAssocList() ?: []);
    }

    public function unlock(string $ip): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::LOCKS))->set($db->quoteName('locked_until') . ' = NULL')->set($db->quoteName('fails') . ' = 0')->set($db->quoteName('strikes') . ' = 0')
                ->where($db->quoteName('ip') . ' = :ip')->bind(':ip', $ip)
        )->execute();
    }

    /** Housekeeping of the counter table (rows idle for 2 days). */
    public function purgeIdle(): void
    {
        $db  = $this->db;
        $old = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName(self::LOCKS))->where($db->quoteName('updated_at') . ' < :o')
                ->where('(' . $db->quoteName('locked_until') . ' IS NULL OR ' . $db->quoteName('locked_until') . ' < :o2)')->bind(':o', $old)->bind(':o2', $old)
        )->execute();
    }
}

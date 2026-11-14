<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Data access of the module: the hit log (#__uxstudio_bt_log) and the ban table (#__uxstudio_bt_bans).
 * IPs are stored only as keyed hashes (GDPR); the optional fleet hash (report_hash) lets UX1 Network app recognise the
 * same offender across sites without ever seeing an address.
 */
final class Store
{
    private const LOG  = '#__uxstudio_bt_log';
    private const BANS = '#__uxstudio_bt_bans';

    public function __construct(private DatabaseInterface $db)
    {
    }

    private static function now(): string
    {
        return Factory::getDate()->toSql();
    }

    /* ----------------------------------------------------------------- log */

    /** @param array<string,mixed> $e ip_hash, user_agent, action, bot_category, bot_name, tier, delay_ms, url, load_score, response_status */
    public function log(array $e): void
    {
        try {
            $db     = $this->db;
            $now    = self::now();
            $hash   = substr((string) ($e['ip_hash'] ?? ''), 0, 64);
            $ua     = mb_substr((string) ($e['user_agent'] ?? ''), 0, 255);
            $action = substr((string) ($e['action'] ?? 'pass'), 0, 20);
            $cat    = substr((string) ($e['bot_category'] ?? ''), 0, 50);
            $name   = substr((string) ($e['bot_name'] ?? ''), 0, 100);
            $tier   = substr((string) ($e['tier'] ?? 'GREEN'), 0, 10);
            $delay  = (int) ($e['delay_ms'] ?? 0);
            $url    = mb_substr((string) ($e['url'] ?? ''), 0, 500);
            $score  = (float) ($e['load_score'] ?? 0);
            $status = (int) ($e['response_status'] ?? 200);
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::LOG))
                    ->columns($db->quoteName(['created', 'ip_hash', 'user_agent', 'action', 'bot_category', 'bot_name', 'tier', 'delay_ms', 'url', 'load_score', 'response_status']))
                    ->values(':c, :h, :ua, :a, :cat, :n, :t, :d, :u, :s, :st')
                    ->bind(':c', $now)->bind(':h', $hash)->bind(':ua', $ua)->bind(':a', $action)->bind(':cat', $cat)->bind(':n', $name)->bind(':t', $tier)
                    ->bind(':d', $delay, ParameterType::INTEGER)->bind(':u', $url)->bind(':s', $score)->bind(':st', $status, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable $ex) {
            // Logging must never break a page.
        }
    }

    /** @param array<string,string> $filters category, action, bot_name @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100, array $filters = []): array
    {
        $db    = $this->db;
        $query = $db->createQuery()->select('*')->from($db->quoteName(self::LOG))->order($db->quoteName('id') . ' DESC');
        if (!empty($filters['category'])) {
            $query->where($db->quoteName('bot_category') . ' = :cat')->bind(':cat', $filters['category']);
        }
        if (!empty($filters['action'])) {
            $query->where($db->quoteName('action') . ' = :act')->bind(':act', $filters['action']);
        }
        if (!empty($filters['bot_name'])) {
            $like = '%' . addcslashes($filters['bot_name'], '%_\\') . '%';
            $query->where($db->quoteName('bot_name') . ' LIKE :bn')->bind(':bn', $like);
        }
        $rows = $db->setQuery($query, 0, max(1, min(500, $limit)))->loadAssocList() ?: [];

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'created' => $r['created'], 'ip_hash' => substr((string) $r['ip_hash'], 0, 12), 'user_agent' => $r['user_agent'], 'action' => $r['action'],
            'bot_category' => $r['bot_category'], 'bot_name' => $r['bot_name'], 'tier' => $r['tier'], 'delay_ms' => (int) $r['delay_ms'], 'url' => $r['url'],
            'load_score' => (float) $r['load_score'], 'response_status' => (int) $r['response_status'],
        ], $rows);
    }

    /** @return array<string,mixed> */
    public function stats(int $hours = 24): array
    {
        $db    = $this->db;
        $hours = max(1, min(720, $hours));
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $where = $db->quoteName('created') . ' >= :s';

        $total = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::LOG))->where($where)->bind(':s', $since))->loadResult();
        $byAct = $db->setQuery(
            $db->createQuery()->select('action, COUNT(*) AS c, AVG(delay_ms) AS avg_delay')->from($db->quoteName(self::LOG))->where($where)->group($db->quoteName('action'))->bind(':s', $since)
        )->loadAssocList() ?: [];
        $top = $db->setQuery(
            $db->createQuery()->select('bot_name, bot_category, COUNT(*) AS c, AVG(delay_ms) AS avg_delay')->from($db->quoteName(self::LOG))->where($where)
                ->group($db->quoteName(['bot_name', 'bot_category']))->order('c DESC')->bind(':s', $since),
            0,
            10
        )->loadAssocList() ?: [];

        $count = static fn (string $action): int => (int) array_sum(array_map(static fn (array $r): int => $r['action'] === $action ? (int) $r['c'] : 0, $byAct));

        return [
            'total'     => $total,
            'blocked'   => $count('block') + $count('ratelimit') + $count('banned'),
            'delayed'   => (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::LOG))->where($where)->where($db->quoteName('delay_ms') . ' > 0')->bind(':s', $since))->loadResult(),
            'bans'      => $count('ban'),
            'by_action' => array_map(static fn (array $r): array => ['action' => $r['action'], 'c' => (int) $r['c'], 'avg_delay' => (int) round((float) $r['avg_delay'])], $byAct),
            'top_bots'  => array_map(static fn (array $r): array => ['bot_name' => $r['bot_name'], 'bot_category' => $r['bot_category'], 'c' => (int) $r['c'], 'avg_delay' => (int) round((float) $r['avg_delay'])], $top),
            'hours'     => $hours,
        ];
    }

    public function clearLog(): int
    {
        $db = $this->db;
        $n  = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::LOG)))->loadResult();
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::LOG))->execute();

        return $n;
    }

    /* ---------------------------------------------------------------- bans */

    /** True when the hashed address is banned in this context right now. HOT PATH: one indexed query. */
    public function isBanned(string $ipHash, string $context): bool
    {
        $db  = $this->db;
        $now = self::now();

        return (bool) $db->setQuery(
            $db->createQuery()->select('1')->from($db->quoteName(self::BANS))
                ->where($db->quoteName('ip_hash') . ' = :h')->where($db->quoteName('context') . ' = :c')->where($db->quoteName('banned_until') . ' > :n')
                ->bind(':h', $ipHash)->bind(':c', $context)->bind(':n', $now),
            0,
            1
        )->loadResult();
    }

    /** Creates a ban. $reportHash: keyed fleet hash of the address ('' = never reported). Returns the ban id. */
    public function ban(string $ipHash, string $context, string $reason, int $seconds, string $reportHash = ''): int
    {
        $db    = $this->db;
        $now   = self::now();
        $until = gmdate('Y-m-d H:i:s', time() + max(60, $seconds));
        $ctx   = substr($context, 0, 100);
        $why   = mb_substr($reason, 0, 190);
        $rep   = $reportHash !== '' ? substr($reportHash, 0, 64) : null;
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName(self::BANS))
                ->columns($db->quoteName(['ip_hash', 'context', 'reason', 'banned_at', 'banned_until', 'report_hash']))->values(':h, :c, :r, :a, :u, :rep')
                ->bind(':h', $ipHash)->bind(':c', $ctx)->bind(':r', $why)->bind(':a', $now)->bind(':u', $until)
                ->bind(':rep', $rep, $rep === null ? ParameterType::NULL : ParameterType::STRING)
        )->execute();

        return (int) $db->insertid();
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} */
    public function listBans(int $page = 1, int $perPage = 20, bool $activeOnly = false): array
    {
        $db      = $this->db;
        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $now     = self::now();
        $apply   = static function ($q) use ($db, $activeOnly, &$now): void {
            if ($activeOnly) {
                $q->where($db->quoteName('banned_until') . ' > :n')->bind(':n', $now);
            }
        };
        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::BANS));
        $apply($count);
        $total = (int) $db->setQuery($count)->loadResult();
        $list  = $db->createQuery()->select('*')->from($db->quoteName(self::BANS))->order($db->quoteName('id') . ' DESC');
        $apply($list);
        $rows = $db->setQuery($list, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];

        return [
            'items' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'ip_hash' => substr((string) $r['ip_hash'], 0, 12), 'context' => $r['context'], 'reason' => $r['reason'],
                'banned_at' => $r['banned_at'], 'banned_until' => $r['banned_until'], 'active' => strtotime($r['banned_until'] . ' UTC') > time(), 'reported' => $r['report_hash'] !== null,
            ], $rows),
            'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function activeBanCount(): int
    {
        $db  = $this->db;
        $now = self::now();

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::BANS))->where($db->quoteName('banned_until') . ' > :n')->bind(':n', $now))->loadResult();
    }

    /** Lifts a ban (the row is kept for UX1 Network app feed, only its end moves to the past). */
    public function unban(int $id): bool
    {
        $db   = $this->db;
        $past = gmdate('Y-m-d H:i:s', time() - 1);
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::BANS))->set($db->quoteName('banned_until') . ' = :p')->where($db->quoteName('id') . ' = :id')
                ->bind(':p', $past)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $db->getAffectedRows() > 0;
    }

    /**
     * Reportable bans for UX1 Network app (cursor based, ascending). Only rows with a fleet hash qualify: bans recorded
     * before a fleet secret was configured stay local forever.
     *
     * @return array<int,array{id:int,report_hash:string,context:string,created_at:string}> same shape as the WordPress node (created_at = UTC, `Y-m-d H:i:s`)
     */
    public function bansSince(int $afterId, int $limit = 200): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'report_hash', 'context', 'banned_at']))->from($db->quoteName(self::BANS))
                ->where($db->quoteName('report_hash') . ' IS NOT NULL')->where($db->quoteName('id') . ' > :a')->order($db->quoteName('id') . ' ASC')->bind(':a', $afterId, ParameterType::INTEGER),
            0,
            max(1, min(500, $limit))
        )->loadAssocList() ?: [];

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'report_hash' => (string) $r['report_hash'], 'context' => (string) $r['context'], 'created_at' => gmdate('Y-m-d H:i:s', (int) strtotime($r['banned_at'] . ' UTC')),
        ], $rows);
    }

    /** Housekeeping: drops bans that ended more than a week ago. */
    public function purgeOldBans(): int
    {
        $db  = $this->db;
        $old = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::BANS))->where($db->quoteName('banned_until') . ' < :o')->where($db->quoteName('report_hash') . ' IS NULL')->bind(':o', $old))->execute();

        return $db->getAffectedRows();
    }
}

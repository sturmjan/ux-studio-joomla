<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Aggregated 404 log: one row per URL with a hit counter, so the list shows what actually loses traffic instead of a
 * raw request stream. No IP addresses are stored.
 */
final class NotFoundLog
{
    public const TABLE = '#__uxstudio_redirects_404';

    /** Hard cap on rows; the least-hit, oldest rows are dropped above it. */
    public const MAX_ROWS = 20000;

    /** Records one 404 (path + query are already normalised). */
    public static function record(string $path, string $query, string $referrer, string $agent): void
    {
        if (Url::isReserved($path)) {
            return;
        }
        $db       = Repository::db();
        $url      = mb_substr(Url::key($path, $query), 0, 2000);
        $hash     = sha1($url);
        $now      = Repository::now();
        $referrer = self::cleanReferrer($referrer);
        $agent    = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $agent) ?? '', 0, 255);
        if (!mb_check_encoding($agent, 'UTF-8')) {
            $agent = '';
        }

        $q = $db->createQuery()->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (url_hash, url, hits, first_seen, last_seen, referrer, user_agent) '
            . 'VALUES (:hash, :url, 1, :first, :last, :ref, :ua) '
            . 'ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), '
            . "referrer = IF(VALUES(referrer) = '', referrer, VALUES(referrer)), user_agent = VALUES(user_agent)"
        )->bind(':hash', $hash)->bind(':url', $url)->bind(':first', $now)->bind(':last', $now)->bind(':ref', $referrer)->bind(':ua', $agent);
        $db->setQuery($q)->execute();

        // A flood of unique URLs must not grow the table without bound between two runs of the daily job.
        if (random_int(1, 300) === 1) {
            self::enforceCap();
        }
    }

    private static function cleanReferrer(string $referrer): string
    {
        $referrer = trim($referrer);
        if ($referrer === '' || !preg_match('#^https?://#i', $referrer) || !mb_check_encoding($referrer, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\s]/', $referrer)) {
            return '';
        }

        return mb_substr($referrer, 0, 500);
    }

    /** Drops the least-hit, oldest rows above MAX_ROWS. */
    public static function enforceCap(): void
    {
        $db    = Repository::db();
        $count = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE)))->loadResult();
        if ($count > self::MAX_ROWS) {
            $over = $count - self::MAX_ROWS;
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' ORDER BY hits ASC, last_seen ASC LIMIT ' . (int) $over)->execute();
        }
    }

    /**
     * Paged list. Each row says whether a rule already covers it and (optionally) which page it probably moved to.
     *
     * @param array<string,mixed> $args search, min_hits, unresolved_only, orderby (hits|last_seen), limit, offset, suggest
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function list(array $args): array
    {
        $db      = Repository::db();
        $minHits = max(1, (int) ($args['min_hits'] ?? 1));
        $search  = trim((string) ($args['search'] ?? ''));
        $like    = '%' . $db->escape(mb_strtolower($search, 'UTF-8'), true) . '%';
        $apply   = static function ($q) use ($db, $minHits, $search, &$like) {
            $q->where($db->quoteName('hits') . ' >= :minhits')->bind(':minhits', $minHits, ParameterType::INTEGER);
            if ($search !== '') {
                $q->where($db->quoteName('url') . ' LIKE :like')->bind(':like', $like);
            }

            return $q;
        };
        $orderby = ($args['orderby'] ?? '') === 'last_seen' ? 'last_seen' : 'hits';
        $limit   = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));
        $unres   = filter_var($args['unresolved_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $suggest = filter_var($args['suggest'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $total = (int) $db->setQuery($apply($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE))))->loadResult();

        // "Unresolved only" depends on the rules (regex included), so it cannot be done in SQL: scan the ordered log in
        // batches, drop the covered rows, then page the remainder. The scan is capped; past the cap the total is an estimate.
        $suggester = $suggest ? new Suggester() : null;
        $items     = [];
        $skipped   = 0;
        $scanned   = 0;
        $found     = 0;
        $batch     = $unres ? 200 : $limit;
        $position  = $unres ? 0 : $offset;
        $scanCap   = 2000;
        $more      = false;
        while (true) {
            $rows = $db->setQuery(
                $apply($db->createQuery()->select('*')->from($db->quoteName(self::TABLE)))
                    ->order($db->quoteName($orderby) . ' DESC, ' . $db->quoteName('id') . ' DESC'),
                $position,
                $batch
            )->loadAssocList() ?: [];
            foreach ($rows as $row) {
                $scanned++;
                $parts = explode('?', (string) $row['url'], 2);
                $match = Repository::match($parts[0], $parts[1] ?? '');
                if ($unres && $match) {
                    continue;
                }
                $found++;
                if ($unres && $skipped < $offset) {
                    $skipped++;
                    continue;
                }
                if (\count($items) >= $limit) {
                    continue; // only counting from here on
                }
                $item = [
                    'id'          => (int) $row['id'],
                    'url'         => (string) $row['url'],
                    'full_url'    => Url::absolute((string) $row['url']),
                    'hits'        => (int) $row['hits'],
                    'first_seen'  => (string) $row['first_seen'],
                    'last_seen'   => (string) $row['last_seen'],
                    'referrer'    => (string) $row['referrer'],
                    'user_agent'  => (string) $row['user_agent'],
                    'resolved_by' => $match ? (int) $match['rule']['id'] : null,
                    'suggestion'  => null,
                ];
                if ($suggester && !$match) {
                    $best = $suggester->candidates((string) $row['url'], '', 1)[0] ?? null;
                    if ($best && $best['score'] >= 50) {
                        $item['suggestion'] = $best;
                    }
                }
                $items[] = $item;
            }
            $position += \count($rows);
            if (!$unres || \count($rows) < $batch) {
                break;
            }
            // Page is full and the scan budget is spent: stop and estimate the rest as unresolved.
            if ($scanned >= $scanCap * 3 || (\count($items) >= $limit && $scanned >= $scanCap)) {
                $more = true;
                break;
            }
        }

        if ($unres) {
            $total = $found + ($more ? max(0, $total - $position) : 0);
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @param int[] $ids */
    public static function delete(array $ids, bool $all = false): int
    {
        $db = Repository::db();
        if ($all) {
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE))->execute();

            return $db->getAffectedRows();
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->whereIn($db->quoteName('id'), $ids))->execute();

        return $db->getAffectedRows();
    }
}

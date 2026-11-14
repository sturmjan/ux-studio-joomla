<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ApiException;

\defined('_JEXEC') or die;

/**
 * IP ban store: one logical ban (single IP or CIDR range, IPv4 + IPv6) expands to rows with binary start / end addresses
 * that the firewall compares against. Origin "local" bans are managed by an administrator (or by the login guard),
 * origin "central" bans are the authoritative set pushed by UX1 Network app (ip-bans/sync) and replaced as a whole.
 */
final class IpBans
{
    private const BANS   = '#__uxstudio_secopt_ip_bans';
    private const RANGES = '#__uxstudio_secopt_ip_ranges';

    /** Safety cap of ranges per central ban (a country ban is a long CIDR list). */
    private const MAX_RANGES = 5000;

    public function __construct(private DatabaseInterface $db)
    {
    }

    private function now(): string
    {
        return Factory::getDate()->toSql();
    }

    private static function flagFile(): string
    {
        // Fixed folder: JPATH_CACHE differs between the site and the administrator client.
        return JPATH_ROOT . '/cache/uxs-secopt-bans.flag';
    }

    /**
     * Fast "is there any ban at all" flag (a tiny file), so a site without bans pays no database query per request.
     * Missing file = unknown: it is recomputed. Expired bans keep the flag at 1 until the next refresh (the query still
     * checks the expiry), which is always the safe direction.
     */
    public function hasBans(): bool
    {
        $flag = @file_get_contents(self::flagFile());
        if ($flag === '0' || $flag === '1') {
            return $flag === '1';
        }

        return $this->refreshFlag();
    }

    /** Recomputes the flag from the table (after every change of the ban set and in housekeeping). */
    public function refreshFlag(): bool
    {
        $has = $this->activeCount() > 0;
        @file_put_contents(self::flagFile(), $has ? '1' : '0', LOCK_EX);

        return $has;
    }

    /** Active ban matching the address (any origin), or null. HOT PATH: one indexed query. */
    public function match(string $ip): ?array
    {
        if (!$this->hasBans()) {
            return null;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $version = \strlen($bin) === 4 ? 4 : 6;
        $hex1    = $hex2 = bin2hex($bin);
        $now     = $this->now();
        $db      = $this->db;
        $query   = $db->createQuery()
            ->select('b.id, b.type, b.value, b.origin')
            ->from($db->quoteName(self::RANGES, 'r'))
            ->join('INNER', $db->quoteName(self::BANS, 'b') . ' ON b.id = r.ban_id')
            ->where('r.ip_version = :v')
            ->where('r.start_ip <= UNHEX(:h1)')
            ->where('r.end_ip >= UNHEX(:h2)')
            ->where('b.is_active = 1')
            ->where('(b.expires_at IS NULL OR b.expires_at > :now)')
            ->bind(':v', $version, ParameterType::INTEGER)
            ->bind(':h1', $hex1)
            ->bind(':h2', $hex2)
            ->bind(':now', $now);
        $row = $db->setQuery($query, 0, 1)->loadAssoc();

        return $row ?: null;
    }

    /** Counts a hit (fire and forget, never throws). */
    public function hit(int $id): void
    {
        try {
            $db  = $this->db;
            $now = $this->now();
            $db->setQuery(
                $db->createQuery()->update($db->quoteName(self::BANS))
                    ->set($db->quoteName('hit_count') . ' = ' . $db->quoteName('hit_count') . ' + 1')
                    ->set($db->quoteName('last_hit_at') . ' = :n')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':n', $now)->bind(':id', $id, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable $e) {
            // Counting must never break the request that is being blocked.
        }
    }

    /**
     * Creates or updates a ban.
     *
     * @param array<string,mixed> $data type (single|cidr, derived from the value when empty), value, label, note, expires_at, is_active, origin, id
     * @param string              $selfIp refuse a ban that would cover this address (lock-out guard); '' = no guard
     * @throws ApiException on invalid input
     */
    public function save(array $data, string $selfIp = '', int $userId = 0): int
    {
        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            throw new ApiException('Value is required.', 422, 'invalid_value');
        }
        $ranges = ClientIp::rangesFor($value);
        if ($ranges === []) {
            throw new ApiException('Not a valid IP address or CIDR range.', 422, 'invalid_value');
        }
        $type = str_contains($value, '/') ? 'cidr' : 'single';
        if ($type === 'single') {
            $value = ClientIp::normalize($value);
        }
        if ($selfIp !== '' && ClientIp::matches($selfIp, [$value])) {
            throw new ApiException('This ban would include your current IP address. Add the address to the allowlist or ban a narrower range.', 422, 'self_ban');
        }

        $db      = $this->db;
        $label   = mb_substr(trim(strip_tags((string) ($data['label'] ?? ''))), 0, 190);
        $note    = mb_substr(trim(strip_tags((string) ($data['note'] ?? ''))), 0, 1000);
        $active  = !empty($data['is_active']) || !\array_key_exists('is_active', $data) ? 1 : 0;
        $expires = $this->normalizeDate($data['expires_at'] ?? null);
        $origin  = ($data['origin'] ?? 'local') === 'auto' ? 'auto' : 'local';
        $id      = (int) ($data['id'] ?? 0);

        if ($id === 0) {
            // The same value and origin is updated in place instead of duplicated.
            $id = (int) $db->setQuery(
                $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName(self::BANS))
                    ->where($db->quoteName('origin') . ' = :o')->where($db->quoteName('value') . ' = :v')
                    ->bind(':o', $origin)->bind(':v', $value)
            )->loadResult();
        }

        $now = $this->now();
        if ($id > 0) {
            $db->setQuery(
                $db->createQuery()->update($db->quoteName(self::BANS))
                    ->set([$db->quoteName('type') . ' = :t', $db->quoteName('value') . ' = :v', $db->quoteName('label') . ' = :l', $db->quoteName('note') . ' = :n',
                           $db->quoteName('is_active') . ' = :a', $db->quoteName('expires_at') . ' = :e'])
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':t', $type)->bind(':v', $value)->bind(':l', $label)->bind(':n', $note)
                    ->bind(':a', $active, ParameterType::INTEGER)->bind(':e', $expires)->bind(':id', $id, ParameterType::INTEGER)
            )->execute();
        } else {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::BANS))
                    ->columns($db->quoteName(['type', 'value', 'label', 'note', 'origin', 'is_active', 'expires_at', 'created_at', 'created_by']))
                    ->values(':t, :v, :l, :n, :o, :a, :e, :c, :u')
                    ->bind(':t', $type)->bind(':v', $value)->bind(':l', $label)->bind(':n', $note)->bind(':o', $origin)
                    ->bind(':a', $active, ParameterType::INTEGER)->bind(':e', $expires)->bind(':c', $now)->bind(':u', $userId, ParameterType::INTEGER)
            )->execute();
            $id = (int) $db->insertid();
        }
        $this->replaceRanges($id, $ranges);
        $this->refreshFlag();

        return $id;
    }

    public function delete(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::RANGES))->where($db->quoteName('ban_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::BANS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        $deleted = $db->getAffectedRows() > 0;
        $this->refreshFlag();

        return $deleted;
    }

    public function setActive(int $id, bool $active): bool
    {
        $db = $this->db;
        $a  = $active ? 1 : 0;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::BANS))->set($db->quoteName('is_active') . ' = :a')->where($db->quoteName('id') . ' = :id')
                ->bind(':a', $a, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
        $this->refreshFlag();

        return true;
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} */
    public function list(string $search = '', string $origin = '', int $page = 1, int $perPage = 20): array
    {
        $db      = $this->db;
        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $where   = static function ($q) use ($db, $search, $origin): void {
            if ($origin === 'local') {
                $q->where($db->quoteName('origin') . ' <> ' . $db->quote('central'));
            } elseif ($origin === 'central') {
                $q->where($db->quoteName('origin') . ' = ' . $db->quote('central'));
            }
            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $q->where('(' . $db->quoteName('value') . ' LIKE :s1 OR ' . $db->quoteName('label') . ' LIKE :s2 OR ' . $db->quoteName('note') . ' LIKE :s3)')
                    ->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like);
            }
        };

        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::BANS));
        $where($count);
        $total = (int) $db->setQuery($count)->loadResult();

        $list = $db->createQuery()->select('*')->from($db->quoteName(self::BANS))->order($db->quoteName('id') . ' DESC');
        $where($list);
        $items = $db->setQuery($list, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];

        return ['items' => array_map([$this, 'present'], $items), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Complete report for UX1 Network app (every ban, no paging). @return array<string,mixed> */
    public function report(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::BANS))->order($db->quoteName('id') . ' DESC'), 0, 5000)->loadAssocList() ?: [];
        $rows = array_map([$this, 'present'], $rows);

        $central = \count(array_filter($rows, static fn (array $r): bool => $r['origin'] === 'central'));

        return [
            'bans'          => $rows,
            'local'         => array_values(array_filter($rows, static fn (array $r): bool => $r['origin'] !== 'central')),
            'central'       => array_values(array_filter($rows, static fn (array $r): bool => $r['origin'] === 'central')),
            'local_count'   => \count($rows) - $central,
            'central_count' => $central,
        ];
    }

    public function activeCount(): int
    {
        $db  = $this->db;
        $now = $this->now();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::BANS))
                ->where($db->quoteName('is_active') . ' = 1')->where('(' . $db->quoteName('expires_at') . ' IS NULL OR ' . $db->quoteName('expires_at') . ' > :n)')
                ->bind(':n', $now)
        )->loadResult();
    }

    /**
     * Replaces EVERY central-origin ban by the set UX1 Network app pushed (idempotent, local bans are never touched).
     * Items that would ban $selfIp are skipped (a central push must not lock this very request out). Runs in ONE transaction
     * (an interrupted sync leaves the previous set intact) and inserts the ranges in bulk.
     *
     * @param array<int,mixed> $items each: central_id, type (single|cidr|country), value, label, note, is_active, ranges (CIDR list, country bans)
     * @return array{synced:int,skipped:int,truncated:int}
     */
    public function replaceCentral(array $items, string $selfIp = ''): array
    {
        $db = $this->db;
        $db->transactionStart();
        try {
            $db->setQuery('DELETE r FROM ' . $db->quoteName(self::RANGES) . ' AS r INNER JOIN ' . $db->quoteName(self::BANS) . ' AS b ON b.id = r.ban_id WHERE b.origin = ' . $db->quote('central'))->execute();
            $db->setQuery($db->createQuery()->delete($db->quoteName(self::BANS))->where($db->quoteName('origin') . ' = ' . $db->quote('central')))->execute();

            $synced        = 0;
            $skipped       = 0;
            $truncated     = 0;
            $now           = $this->now();
            $centralOrigin = 'central';
            $pending       = [];
            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $value = trim((string) ($item['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                // Country bans arrive with pre-computed CIDR ranges; single IP / CIDR are derived from the value.
                $type   = \in_array($item['type'] ?? '', ['single', 'cidr', 'country'], true) ? (string) $item['type'] : (str_contains($value, '/') ? 'cidr' : 'single');
                $ranges = [];
                if ($type === 'country') {
                    $list = array_values((array) ($item['ranges'] ?? []));
                    $truncated += max(0, \count($list) - self::MAX_RANGES);
                    foreach (\array_slice($list, 0, self::MAX_RANGES) as $cidr) {
                        $ranges = array_merge($ranges, ClientIp::rangesFor(trim((string) $cidr)));
                    }
                } else {
                    $ranges = ClientIp::rangesFor($value);
                }
                if ($ranges === []) {
                    $skipped++;
                    continue;
                }
                if ($selfIp !== '' && $this->rangesCover($ranges, $selfIp)) {
                    $skipped++;
                    continue;
                }

                $label   = mb_substr(trim(strip_tags((string) ($item['label'] ?? ''))), 0, 190);
                $note    = mb_substr(trim(strip_tags((string) ($item['note'] ?? ''))), 0, 1000);
                $active  = !\array_key_exists('is_active', $item) || !empty($item['is_active']) ? 1 : 0;
                $central = isset($item['central_id']) ? (int) $item['central_id'] : null;
                $expires = $this->normalizeDate($item['expires_at'] ?? null);
                $value   = mb_substr($value, 0, 190);
                $db->setQuery(
                    $db->createQuery()->insert($db->quoteName(self::BANS))
                        ->columns($db->quoteName(['type', 'value', 'label', 'note', 'origin', 'central_id', 'is_active', 'expires_at', 'created_at']))
                        ->values(':t, :v, :l, :n, :o, :cid, :a, :e, :c')
                        ->bind(':t', $type)->bind(':v', $value)->bind(':l', $label)->bind(':n', $note)->bind(':o', $centralOrigin)
                        ->bind(':cid', $central, $central === null ? ParameterType::NULL : ParameterType::INTEGER)
                        ->bind(':a', $active, ParameterType::INTEGER)->bind(':e', $expires)->bind(':c', $now)
                )->execute();
                $banId = (int) $db->insertid();
                foreach ($ranges as [$version, $start, $end]) {
                    $pending[] = '(' . $banId . ', ' . (int) $version . ', UNHEX(' . $db->quote($start) . '), UNHEX(' . $db->quote($end) . '))';
                }
                if (\count($pending) >= 500) {
                    $this->flushRanges($pending);
                    $pending = [];
                }
                $synced++;
            }
            $this->flushRanges($pending);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
        $this->refreshFlag();

        return ['synced' => $synced, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /** @param string[] $tuples prepared "(ban, version, UNHEX(..), UNHEX(..))" value lists (hex strings come from bin2hex) */
    private function flushRanges(array $tuples): void
    {
        if ($tuples === []) {
            return;
        }
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(self::RANGES) . ' (' . implode(', ', $db->quoteName(['ban_id', 'ip_version', 'start_ip', 'end_ip'])) . ') VALUES ' . implode(', ', $tuples)
        )->execute();
    }

    /** @param array<int,array{0:int,1:string,2:string}> $ranges */
    private function replaceRanges(int $banId, array $ranges): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::RANGES))->where($db->quoteName('ban_id') . ' = :id')->bind(':id', $banId, ParameterType::INTEGER))->execute();
        foreach ($ranges as [$version, $start, $end]) {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::RANGES))
                    ->columns($db->quoteName(['ban_id', 'ip_version', 'start_ip', 'end_ip']))
                    ->values(':b, :v, UNHEX(:s), UNHEX(:e)')
                    ->bind(':b', $banId, ParameterType::INTEGER)->bind(':v', $version, ParameterType::INTEGER)->bind(':s', $start)->bind(':e', $end)
            )->execute();
        }
    }

    /** @param array<int,array{0:int,1:string,2:string}> $ranges */
    private function rangesCover(array $ranges, string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as [$version, $start, $end]) {
            $s = (string) hex2bin($start);
            $e = (string) hex2bin($end);
            if (\strlen($s) === \strlen($bin) && strcmp($bin, $s) >= 0 && strcmp($bin, $e) <= 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $row): array
    {
        $expires = $row['expires_at'] ?? null;

        return [
            'id'          => (int) $row['id'],
            'type'        => (string) $row['type'],
            'value'       => (string) $row['value'],
            'label'       => (string) ($row['label'] ?? ''),
            'note'        => (string) ($row['note'] ?? ''),
            'origin'      => (string) $row['origin'],
            'central_id'  => isset($row['central_id']) ? (int) $row['central_id'] : null,
            'is_active'   => (int) $row['is_active'] === 1,
            'expires_at'  => $expires ?: null,
            'expired'     => $expires && strtotime($expires . ' UTC') < time(),
            'hit_count'   => (int) ($row['hit_count'] ?? 0),
            'last_hit_at' => $row['last_hit_at'] ?? null,
            'created_at'  => $row['created_at'] ?? null,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value . (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', (string) $value) ? '' : ' UTC'));

        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }
}

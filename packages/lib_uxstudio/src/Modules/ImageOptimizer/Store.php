<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * One row per processed file (path relative to the site root). A row whose file_size equals the size of the file on
 * disk means "already handled": the file is never compressed a second time unless the file itself changed.
 */
final class Store
{
    public const TABLE = '#__uxstudio_image_optimizer';

    public function __construct(private DatabaseInterface $db)
    {
    }

    public static function hash(string $rel): string
    {
        return sha1($rel);
    }

    /** @return array<string,mixed>|null */
    public function get(string $rel): ?array
    {
        $hash = self::hash($rel);
        $db   = $this->db;
        $row  = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('path_hash') . ' = :h')->bind(':h', $hash)
        )->loadAssoc();

        return $row ?: null;
    }

    /**
     * Known file sizes of several paths at once.
     *
     * @param string[] $rels
     *
     * @return array<string,int> path hash => recorded file size
     */
    public function sizes(array $rels): array
    {
        if (!$rels) {
            return [];
        }
        $db     = $this->db;
        $hashes = array_map([self::class, 'hash'], $rels);
        $query  = $db->createQuery()->select($db->quoteName(['path_hash', 'file_size']))->from($db->quoteName(self::TABLE))
            ->whereIn($db->quoteName('path_hash'), $hashes, ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $out[$row['path_hash']] = (int) $row['file_size'];
        }

        return $out;
    }

    /** @param array<string,mixed> $row path, status, original_size, file_size, webp_size, avif_size, width, height, backup, source, note */
    public function save(array $row): void
    {
        $db   = $this->db;
        $now  = Factory::getDate()->toSql();
        $hash = self::hash((string) $row['path']);
        $vals = [
            'path'          => (string) $row['path'],
            'status'        => (string) $row['status'],
            'original_size' => (int) $row['original_size'],
            'file_size'     => (int) $row['file_size'],
            'webp_size'     => (int) ($row['webp_size'] ?? 0),
            'avif_size'     => (int) ($row['avif_size'] ?? 0),
            'width'         => (int) ($row['width'] ?? 0),
            'height'        => (int) ($row['height'] ?? 0),
            'backup'        => (int) !empty($row['backup']),
            'source'        => (string) ($row['source'] ?? ''),
            'note'          => substr((string) ($row['note'] ?? ''), 0, 190),
        ];

        $existing = $this->get((string) $row['path']);
        if ($existing) {
            $query = $db->createQuery()->update($db->quoteName(self::TABLE));
            foreach ($vals as $col => $v) {
                $query->set($db->quoteName($col) . ' = :' . $col)->bind(':' . $col, $vals[$col], \is_int($v) ? ParameterType::INTEGER : ParameterType::STRING);
            }
            $query->set($db->quoteName('updated') . ' = :upd')->bind(':upd', $now)
                ->where($db->quoteName('path_hash') . ' = :h')->bind(':h', $hash);
            $db->setQuery($query)->execute();

            return;
        }

        $query = $db->createQuery()->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName(array_merge(['path_hash'], array_keys($vals), ['created', 'updated'])));
        $placeholders = [':path_hash'];
        $query->bind(':path_hash', $hash);
        foreach ($vals as $col => $v) {
            $placeholders[] = ':' . $col;
            $query->bind(':' . $col, $vals[$col], \is_int($v) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $placeholders[] = ':created';
        $placeholders[] = ':updated';
        $query->bind(':created', $now)->bind(':updated', $now)->values(implode(',', $placeholders));
        $db->setQuery($query)->execute();
    }

    public function delete(string $rel): void
    {
        $hash = self::hash($rel);
        $db   = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('path_hash') . ' = :h')->bind(':h', $hash))->execute();
    }

    /** Deletes the rows of every path below a folder prefix such as "images/photos/". */
    public function deletePrefix(string $prefix): void
    {
        $db   = $this->db;
        $like = addcslashes($prefix, '%_\\') . '%';
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('path') . ' LIKE :p')->bind(':p', $like))->execute();
    }

    /** @return string[] every recorded path */
    public function paths(): array
    {
        $db = $this->db;

        return array_map('strval', $db->setQuery($db->createQuery()->select($db->quoteName('path'))->from($db->quoteName(self::TABLE)))->loadColumn() ?: []);
    }

    /** @return array{files:int,optimized:int,original:int,current:int,saved:int,webp:int,avif:int,backups:int,webp_files:int,avif_files:int} */
    public function totals(): array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select([
                'COUNT(*) AS files',
                "SUM(CASE WHEN status = 'optimized' THEN 1 ELSE 0 END) AS optimized",
                "COALESCE(SUM(CASE WHEN status = 'optimized' THEN original_size ELSE 0 END), 0) AS original",
                "COALESCE(SUM(CASE WHEN status = 'optimized' THEN file_size ELSE 0 END), 0) AS current_size",
                'COALESCE(SUM(webp_size), 0) AS webp',
                'COALESCE(SUM(avif_size), 0) AS avif',
                'COALESCE(SUM(backup), 0) AS backups',
                'SUM(CASE WHEN webp_size > 0 THEN 1 ELSE 0 END) AS webp_files',
                'SUM(CASE WHEN avif_size > 0 THEN 1 ELSE 0 END) AS avif_files',
            ])->from($db->quoteName(self::TABLE))
        )->loadAssoc() ?: [];

        $original = (int) ($row['original'] ?? 0);
        $current  = (int) ($row['current_size'] ?? 0);

        return [
            'files'      => (int) ($row['files'] ?? 0),
            'optimized'  => (int) ($row['optimized'] ?? 0),
            'original'   => $original,
            'current'    => $current,
            'saved'      => max(0, $original - $current),
            'webp'       => (int) ($row['webp'] ?? 0),
            'avif'       => (int) ($row['avif'] ?? 0),
            'backups'    => (int) ($row['backups'] ?? 0),
            'webp_files' => (int) ($row['webp_files'] ?? 0),
            'avif_files' => (int) ($row['avif_files'] ?? 0),
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function page(int $page, int $per, string $status, string $search): array
    {
        $db    = $this->db;
        $where = [];
        $bind  = [];
        if (\in_array($status, ['optimized', 'skipped', 'failed', 'restored'], true)) {
            $where[] = $db->quoteName('status') . ' = :st';
            $bind[':st'] = $status;
        }
        if ($search !== '') {
            $where[] = $db->quoteName('path') . ' LIKE :q';
            $bind[':q'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE));
        $list  = $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->order($db->quoteName('updated') . ' DESC, ' . $db->quoteName('id') . ' DESC');
        foreach ($where as $w) {
            $count->where($w);
            $list->where($w);
        }
        foreach ($bind as $k => $v) {
            $count->bind($k, $bind[$k]);
            $list->bind($k, $bind[$k]);
        }
        $total = (int) $db->setQuery($count)->loadResult();
        $items = $db->setQuery($list, max(0, ($page - 1) * $per), $per)->loadAssocList() ?: [];

        return ['items' => $items, 'total' => $total];
    }
}

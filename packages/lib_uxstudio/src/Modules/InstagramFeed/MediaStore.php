<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Database cache of the posts (#__uxstudio_instagram_media) and small key/value state (#__uxstudio_instagram_meta).
 * Dates are UTC. Every value goes through bind() or quote().
 */
final class MediaStore
{
    public const MEDIA = '#__uxstudio_instagram_media';
    public const META  = '#__uxstudio_instagram_meta';

    public function __construct(private DatabaseInterface $db)
    {
    }

    // ---------------------------------------------------------------- meta

    public function getMeta(string $name, string $default = ''): string
    {
        $db  = $this->db;
        $val = $db->setQuery(
            $db->createQuery()->select($db->quoteName('value'))->from($db->quoteName(self::META))
                ->where($db->quoteName('name') . ' = :n')->bind(':n', $name)
        )->loadResult();

        return $val === null ? $default : (string) $val;
    }

    public function setMeta(string $name, string $value): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $update = static fn () => $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::META))
                ->set($db->quoteName('value') . ' = :v')->set($db->quoteName('updated_at') . ' = :u')->where($db->quoteName('name') . ' = :n')
                ->bind(':v', $value)->bind(':u', $now)->bind(':n', $name)
        )->execute();
        $update();
        if ($db->getAffectedRows() > 0) {
            return;
        }
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::META))
                    ->columns($db->quoteName(['name', 'value', 'updated_at']))->values(':n, :v, :u')
                    ->bind(':n', $name)->bind(':v', $value)->bind(':u', $now)
            )->execute();
        } catch (\Throwable $e) {
            $update(); // the row exists already (unchanged value, or a concurrent insert)
        }
    }

    /**
     * Atomic lock: the primary key of the meta table decides who gets it. A lock older than $ttl seconds is stale and taken over.
     */
    public function acquireLock(string $name, int $ttl): bool
    {
        $db  = $this->db;
        $cut = time() - $ttl;
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName(self::META))
                ->where($db->quoteName('name') . ' = :n')->where('CAST(' . $db->quoteName('value') . ' AS UNSIGNED) < :c')
                ->bind(':n', $name)->bind(':c', $cut, ParameterType::INTEGER)
        )->execute();

        $val = (string) time();
        $now = Factory::getDate()->toSql();
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName(self::META))
                    ->columns($db->quoteName(['name', 'value', 'updated_at']))->values(':n, :v, :u')
                    ->bind(':n', $name)->bind(':v', $val)->bind(':u', $now)
            )->execute();
        } catch (\Throwable $e) {
            return false; // duplicate key = somebody else holds it
        }

        return true;
    }

    public function deleteMeta(string $name): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::META))->where($db->quoteName('name') . ' = :n')->bind(':n', $name))->execute();
    }

    /** @return array<string,mixed> */
    public function getJson(string $name): array
    {
        $data = json_decode($this->getMeta($name), true);

        return \is_array($data) ? $data : [];
    }

    // ---------------------------------------------------------------- posts

    /**
     * Inserts or updates a normalised post. The moderation flag (is_hidden) and an already downloaded local copy survive
     * an update. @return 'new'|'updated'
     */
    public function upsert(array $row): string
    {
        $db       = $this->db;
        $existing = $this->byInstagramId($row['instagram_id']);
        $now      = Factory::getDate()->toSql();

        $values = [
            'media_type'      => $row['media_type'],
            'media_url'       => $row['media_url'],
            'thumbnail_url'   => $row['thumbnail_url'],
            'permalink'       => $row['permalink'],
            'caption'         => $row['caption'],
            'hashtags'        => $row['hashtags'] === [] ? null : json_encode($row['hashtags'], JSON_UNESCAPED_UNICODE),
            'media_timestamp' => $row['media_timestamp'],
            'like_count'      => $row['like_count'],
            'comments_count'  => $row['comments_count'],
            'synced_at'       => $now,
        ];

        if ($existing === null) {
            $values['instagram_id'] = $row['instagram_id'];
            $values['local_file']   = '';
            // insertObject() binds every value; null properties are skipped (the columns are nullable).
            $object = (object) $values;
            $db->insertObject(self::MEDIA, $object);

            return 'new';
        }

        $values['id'] = (int) $existing['id'];
        $object = (object) $values;
        $db->updateObject(self::MEDIA, $object, 'id', true);

        return 'updated';
    }

    /** @return array<string,mixed>|null */
    public function byInstagramId(string $instagramId): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::MEDIA))->where($db->quoteName('instagram_id') . ' = :i')->bind(':i', $instagramId)
        )->loadAssoc();

        return \is_array($row) ? $this->format($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::MEDIA))->where($db->quoteName('id') . ' = :i')->bind(':i', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return \is_array($row) ? $this->format($row) : null;
    }

    public function setLocalFile(int $id, string $file): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::MEDIA))->set($db->quoteName('local_file') . ' = :f')->set($db->quoteName('dl_fail') . ' = 0')
                ->where($db->quoteName('id') . ' = :i')->bind(':f', $file)->bind(':i', $id, ParameterType::INTEGER)
        )->execute();
    }

    public function setHidden(int $id, bool $hidden): void
    {
        $db = $this->db;
        $h  = $hidden ? 1 : 0;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::MEDIA))->set($db->quoteName('is_hidden') . ' = :h')
                ->where($db->quoteName('id') . ' = :i')->bind(':h', $h, ParameterType::INTEGER)->bind(':i', $id, ParameterType::INTEGER)
        )->execute();
    }

    /** @param int[] $ids */
    public function delete(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i) => $i > 0));
        if (!$ids) {
            return;
        }
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::MEDIA))->whereIn($db->quoteName('id'), $ids))->execute();
    }

    public function clear(): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::MEDIA))->execute();
    }

    /**
     * Posts for the admin list.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function page(int $page, int $perPage): array
    {
        $db      = $this->db;
        $perPage = max(1, min(100, $perPage));
        $total   = $this->count();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, $page));
        $offset  = ($page - 1) * $perPage;

        $rows = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::MEDIA))
                ->order($db->quoteName('media_timestamp') . ' DESC, ' . $db->quoteName('id') . ' DESC'),
            $offset,
            $perPage
        )->loadAssocList() ?: [];

        return ['items' => array_map(fn (array $r) => $this->format($r), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Visible posts for the front end, newest first (hashtag filtering is done by the caller on this window).
     *
     * @param string[] $types allowed media types
     * @return array<int,array<string,mixed>>
     */
    public function visible(array $types, int $limit): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('*')->from($db->quoteName(self::MEDIA))->where($db->quoteName('is_hidden') . ' = 0');
        if ($types) {
            $q->whereIn($db->quoteName('media_type'), $types, ParameterType::STRING);
        }
        $q->order($db->quoteName('media_timestamp') . ' DESC, ' . $db->quoteName('id') . ' DESC');
        $rows = $db->setQuery($q, 0, max(1, min(200, $limit)))->loadAssocList() ?: [];

        return array_map(fn (array $r) => $this->format($r), $rows);
    }

    /**
     * Posts that still lack a local copy of the image. Posts whose download failed $maxFail times are left out (they are
     * given another chance by resetDownloadFailures()), the others with fewer failures come first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function withoutLocalFile(int $limit, int $maxFail = 3): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::MEDIA))->where($db->quoteName('local_file') . " = ''")
                ->where($db->quoteName('dl_fail') . ' < :m')->bind(':m', $maxFail, ParameterType::INTEGER)
                ->order($db->quoteName('dl_fail') . ' ASC, ' . $db->quoteName('media_timestamp') . ' DESC'),
            0,
            max(1, $limit)
        )->loadAssocList() ?: [];

        return array_map(fn (array $r) => $this->format($r), $rows);
    }

    public function markDownloadFailed(int $id): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::MEDIA))->set($db->quoteName('dl_fail') . ' = ' . $db->quoteName('dl_fail') . ' + 1')
                ->where($db->quoteName('id') . ' = :i')->bind(':i', $id, ParameterType::INTEGER)
        )->execute();
    }

    public function resetDownloadFailures(): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->update($db->quoteName(self::MEDIA))->set($db->quoteName('dl_fail') . ' = 0')->where($db->quoteName('dl_fail') . ' > 0'))->execute();
    }

    /** @return array<int,array{id:int,instagram_id:string,media_timestamp:?string,local_file:string}> */
    public function index(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'instagram_id', 'media_timestamp', 'local_file']))->from($db->quoteName(self::MEDIA))
        )->loadAssocList() ?: [];

        return array_map(static fn (array $r) => [
            'id'              => (int) $r['id'],
            'instagram_id'    => (string) $r['instagram_id'],
            'media_timestamp' => $r['media_timestamp'] === null ? null : (string) $r['media_timestamp'],
            'local_file'      => (string) $r['local_file'],
        ], $rows);
    }

    public function count(bool $hiddenOnly = false): int
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::MEDIA));
        if ($hiddenOnly) {
            $q->where($db->quoteName('is_hidden') . ' = 1');
        }

        return (int) $db->setQuery($q)->loadResult();
    }

    public function countWithLocalFile(): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::MEDIA))->where($db->quoteName('local_file') . " <> ''")
        )->loadResult();
    }

    /** @return string[] file names of all stored local copies */
    public function localFiles(): array
    {
        $db = $this->db;

        return array_map('strval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('local_file'))->from($db->quoteName(self::MEDIA))->where($db->quoteName('local_file') . " <> ''")
        )->loadColumn() ?: []);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function format(array $row): array
    {
        $tags = json_decode((string) ($row['hashtags'] ?? ''), true);

        return [
            'id'              => (int) $row['id'],
            'instagram_id'    => (string) $row['instagram_id'],
            'media_type'      => (string) $row['media_type'],
            'media_url'       => (string) $row['media_url'],
            'thumbnail_url'   => (string) $row['thumbnail_url'],
            'permalink'       => (string) $row['permalink'],
            'caption'         => (string) ($row['caption'] ?? ''),
            'hashtags'        => \is_array($tags) ? array_map('strval', $tags) : [],
            'media_timestamp' => $row['media_timestamp'] === null ? null : (string) $row['media_timestamp'],
            'like_count'      => $row['like_count'] === null ? null : (int) $row['like_count'],
            'comments_count'  => $row['comments_count'] === null ? null : (int) $row['comments_count'],
            'local_file'      => (string) $row['local_file'],
            'is_hidden'       => (bool) $row['is_hidden'],
            'synced_at'       => (string) $row['synced_at'],
        ];
    }
}

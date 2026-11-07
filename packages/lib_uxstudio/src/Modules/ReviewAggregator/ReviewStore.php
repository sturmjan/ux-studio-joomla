<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Data access of the review table (#__uxstudio_reviews) - the local cache of all reviews of the site.
 * Reviews are never deleted by retention or by turning the module off.
 */
final class ReviewStore
{
    public const ORDERS = [
        'newest'      => 'review_date DESC, id DESC',
        'oldest'      => 'review_date ASC, id ASC',
        'best_rating' => 'rating DESC, review_date DESC, id DESC',
    ];

    private const COLUMNS = ['id', 'source', 'origin', 'external_id', 'author', 'rating', 'text', 'review_date', 'imported_at', 'visible'];

    /** CA source ids => local ids shown on the site. */
    private const SOURCE_ALIASES = ['google_maps' => 'google', 'google_places' => 'google', 'googlemaps' => 'google', 'mapy_cz' => 'mapycz', 'mapy' => 'mapycz'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    public static function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        $source = preg_replace('/[^a-z0-9_-]/', '', $source) ?? '';
        $source = self::SOURCE_ALIASES[$source] ?? $source;

        return substr($source, 0, 32);
    }

    /**
     * Normalises one review from the central app, an import file or the manual form. Returns null when unusable.
     *
     * @param array<string,mixed> $item
     * @return array{source:string,external_id:string,author:string,rating:int,text:string,review_date:?string}|null
     */
    public static function normalize(array $item, string $defaultSource = ''): ?array
    {
        $rawSource = isset($item['source']) && \is_scalar($item['source']) ? (string) $item['source'] : '';
        $source    = self::normalizeSource($rawSource !== '' ? $rawSource : $defaultSource);
        if ($source === '') {
            return null;
        }
        $text   = $item['review_text'] ?? $item['text'] ?? '';
        $author = $item['author_name'] ?? $item['author'] ?? '';
        $rating = $item['rating'] ?? 0;
        $date   = $item['review_date'] ?? $item['date'] ?? '';

        $clean = static fn (mixed $v, int $max): string => mb_substr(trim(strip_tags(str_replace("\0", '', \is_scalar($v) ? (string) $v : ''))), 0, $max);

        $ts   = \is_scalar($date) && trim((string) $date) !== '' ? strtotime(trim((string) $date)) : false;
        $rate = is_numeric($rating) ? (int) round((float) $rating) : 0;

        return [
            'source'      => $source,
            'external_id' => $clean($item['external_id'] ?? '', 191),
            'author'      => $clean($author, 255),
            'rating'      => max(0, min(5, $rate)),
            'text'        => $clean($text, 10000),
            'review_date' => $ts ? gmdate('Y-m-d H:i:s', $ts) : null,
        ];
    }

    /** Stable key: the source's own id when known, otherwise the content, so repeated imports never duplicate a review. */
    public static function dedupHash(array $row): string
    {
        $tail = $row['external_id'] !== '' ? 'ext:' . $row['external_id'] : 'sig:' . $row['author'] . '|' . ($row['review_date'] ?? '') . '|' . $row['text'];

        return sha1($row['source'] . '|' . $tail);
    }

    /**
     * Inserts or updates normalised rows. Content of an existing row is refreshed (owner replies, edits) but the local
     * moderation flag `visible` is never touched.
     *
     * @param array<int,array<string,mixed>> $rows output of normalize()
     * @return array{fetched:int,updated:int}
     */
    public function upsertMany(array $rows, string $origin): array
    {
        $db      = $this->db;
        $now     = Factory::getDate()->toSql();
        $fetched = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $hash = self::dedupHash($row);
            $id   = (int) $db->setQuery(
                $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('dedup_hash') . ' = :h')->bind(':h', $hash)
            )->loadResult();

            $source = (string) $row['source'];
            $ext    = (string) $row['external_id'];
            $author = (string) $row['author'];
            $rating = (int) $row['rating'];
            $text   = (string) $row['text'];
            $date   = $row['review_date'];
            $dtype  = $date === null ? ParameterType::NULL : ParameterType::STRING;

            if ($id > 0) {
                $q = $db->createQuery()->update($db->quoteName('#__uxstudio_reviews'))
                    ->set($db->quoteName('author') . ' = :a')->set($db->quoteName('rating') . ' = :r')->set($db->quoteName('text') . ' = :t')
                    ->set($db->quoteName('review_date') . ' = :d')->set($db->quoteName('imported_at') . ' = :n')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':a', $author)->bind(':r', $rating, ParameterType::INTEGER)->bind(':t', $text)
                    ->bind(':d', $date, $dtype)->bind(':n', $now)->bind(':id', $id, ParameterType::INTEGER);
                $db->setQuery($q)->execute();
                ++$updated;
                continue;
            }

            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_reviews'))
                ->columns($db->quoteName(['source', 'origin', 'external_id', 'dedup_hash', 'author', 'rating', 'text', 'review_date', 'imported_at', 'visible']))
                ->values(':s, :o, :e, :h, :a, :r, :t, :d, :n, 1')
                ->bind(':s', $source)->bind(':o', $origin)->bind(':e', $ext)->bind(':h', $hash)->bind(':a', $author)
                ->bind(':r', $rating, ParameterType::INTEGER)->bind(':t', $text)
                ->bind(':d', $date, $dtype)->bind(':n', $now);
            $db->setQuery($q)->execute();
            ++$fetched;
        }

        return ['fetched' => $fetched, 'updated' => $updated];
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName(self::COLUMNS))->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return $row ? self::format($row) : null;
    }

    /**
     * Admin list with filters and paging.
     *
     * @param array{source?:string,visible?:?bool,min_rating?:int,q?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $db     = $this->db;
        $source = (string) ($filters['source'] ?? '');
        $vis    = isset($filters['visible']) ? ($filters['visible'] ? 1 : 0) : null;
        $minr   = (int) ($filters['min_rating'] ?? 0);
        $like   = ($filters['q'] ?? '') !== '' ? '%' . addcslashes((string) $filters['q'], '%_\\') . '%' : '';

        $apply = function ($q) use ($db, &$source, &$vis, &$minr, &$like) {
            if ($source !== '') {
                $q->where($db->quoteName('source') . ' = :src')->bind(':src', $source);
            }
            if ($vis !== null) {
                $q->where($db->quoteName('visible') . ' = :vis')->bind(':vis', $vis, ParameterType::INTEGER);
            }
            if ($minr > 0) {
                $q->where($db->quoteName('rating') . ' >= :minr')->bind(':minr', $minr, ParameterType::INTEGER);
            }
            if ($like !== '') {
                $q->where('(' . $db->quoteName('author') . ' LIKE :q1 OR ' . $db->quoteName('text') . ' LIKE :q2)')->bind(':q1', $like)->bind(':q2', $like);
            }

            return $q;
        };

        $total   = (int) $db->setQuery($apply($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_reviews'))))->loadResult();
        $perPage = max(1, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, $page));
        $list    = $apply($db->createQuery()->select($db->quoteName(self::COLUMNS))->from($db->quoteName('#__uxstudio_reviews')))->order(self::ORDERS['newest']);
        $rows    = $db->setQuery($list, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];

        return ['items' => array_map([self::class, 'format'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function setVisible(int $id, bool $visible): void
    {
        $db   = $this->db;
        $flag = $visible ? 1 : 0;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_reviews'))->set($db->quoteName('visible') . ' = :v')->where($db->quoteName('id') . ' = :id')
                ->bind(':v', $flag, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Edits a manual / imported review. A row that came with its own id (import with an id column) keeps external_id, source
     * and dedup_hash, so importing the same file again still finds it instead of adding a duplicate. Other rows get the new
     * content hash.
     *
     * @param array<string,mixed> $row normalised
     */
    public function updateManual(int $id, array $row, string $hash): void
    {
        $db     = $this->db;
        $author = (string) $row['author'];
        $rating = (int) $row['rating'];
        $text   = (string) $row['text'];
        $date   = $row['review_date'];
        $dtype  = $date === null ? ParameterType::NULL : ParameterType::STRING;

        $keepKey = (string) $db->setQuery(
            $db->createQuery()->select($db->quoteName('external_id'))->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadResult() !== '';

        $q = $db->createQuery()->update($db->quoteName('#__uxstudio_reviews'))
            ->set($db->quoteName('author') . ' = :a')->set($db->quoteName('rating') . ' = :r')->set($db->quoteName('text') . ' = :t')->set($db->quoteName('review_date') . ' = :d')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':a', $author)->bind(':r', $rating, ParameterType::INTEGER)->bind(':t', $text)->bind(':d', $date, $dtype)->bind(':id', $id, ParameterType::INTEGER);
        if (!$keepKey) {
            $source = (string) $row['source'];
            $q->set($db->quoteName('source') . ' = :s')->set($db->quoteName('external_id') . " = ''")->set($db->quoteName('dedup_hash') . ' = :h')->bind(':s', $source)->bind(':h', $hash);
        }
        $db->setQuery($q)->execute();
    }

    public function hashExists(string $hash, int $exceptId = 0): bool
    {
        $db = $this->db;

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('dedup_hash') . ' = :h')->where($db->quoteName('id') . ' <> :id')
                ->bind(':h', $hash)->bind(':id', $exceptId, ParameterType::INTEGER)
        )->loadResult() > 0;
    }

    public function delete(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /**
     * Visible reviews for the front end.
     *
     * @param array{source?:string,min_rating?:int,order?:string,limit?:int} $args
     * @return array<int,array<string,mixed>>
     */
    public function visible(array $args): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select($db->quoteName(['id', 'source', 'author', 'rating', 'text', 'review_date']))
            ->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('visible') . ' = 1');
        $source = (string) ($args['source'] ?? '');
        $min    = (int) ($args['min_rating'] ?? 0);
        if ($source !== '') {
            $q->where($db->quoteName('source') . ' = :src')->bind(':src', $source);
        }
        if ($min > 0) {
            $q->where($db->quoteName('rating') . ' >= :minr')->bind(':minr', $min, ParameterType::INTEGER);
        }
        $q->order(self::ORDERS[(string) ($args['order'] ?? 'newest')] ?? self::ORDERS['newest']);

        return $db->setQuery($q, 0, max(1, min(100, (int) ($args['limit'] ?? 10))))->loadAssocList() ?: [];
    }

    /**
     * Average and count of all visible rated reviews (what AggregateRating states). The display filters (minimum rating,
     * count) are deliberately NOT applied: the rating must describe all the reviews of the business, not a selection.
     *
     * @return array{count:int,average:float}
     */
    public function aggregate(string $source = ''): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select(['COUNT(*) AS c', 'AVG(' . $db->quoteName('rating') . ') AS a'])
            ->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('visible') . ' = 1')->where($db->quoteName('rating') . ' > 0');
        if ($source !== '') {
            $q->where($db->quoteName('source') . ' = :src')->bind(':src', $source);
        }
        $row = $db->setQuery($q)->loadAssoc() ?: [];

        return ['count' => (int) ($row['c'] ?? 0), 'average' => round((float) ($row['a'] ?? 0), 2)];
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $db   = $this->db;
        $avg  = 'AVG(NULLIF(' . $db->quoteName('rating') . ', 0))';
        $all  = $db->setQuery($db->createQuery()->select(['COUNT(*) AS c', 'COALESCE(SUM(' . $db->quoteName('visible') . '), 0) AS v', $avg . ' AS a'])->from($db->quoteName('#__uxstudio_reviews')))->loadAssoc() ?: [];
        $by   = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('source'), 'COUNT(*) AS c', $avg . ' AS a'])->from($db->quoteName('#__uxstudio_reviews'))->group($db->quoteName('source'))->order('c DESC')
        )->loadAssocList() ?: [];
        $dist = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('rating'), 'COUNT(*) AS c'])->from($db->quoteName('#__uxstudio_reviews'))->where($db->quoteName('rating') . ' > 0')->group($db->quoteName('rating'))
        )->loadAssocList('rating') ?: [];

        $distribution = [];
        for ($i = 5; $i >= 1; --$i) {
            $distribution[] = ['rating' => $i, 'count' => (int) ($dist[$i]['c'] ?? 0)];
        }

        return [
            'total'        => (int) ($all['c'] ?? 0),
            'visible'      => (int) ($all['v'] ?? 0),
            'average'      => round((float) ($all['a'] ?? 0), 2),
            'by_source'    => array_map(static fn (array $r): array => ['source' => (string) $r['source'], 'count' => (int) $r['c'], 'average' => round((float) $r['a'], 2)], $by),
            'distribution' => $distribution,
        ];
    }

    /** @return string[] */
    public function sources(): array
    {
        $db = $this->db;

        return array_map('strval', $db->setQuery($db->createQuery()->select('DISTINCT ' . $db->quoteName('source'))->from($db->quoteName('#__uxstudio_reviews'))->order($db->quoteName('source')))->loadColumn() ?: []);
    }

    public function getMeta(string $name, string $default = ''): string
    {
        $db = $this->db;
        $v  = $db->setQuery($db->createQuery()->select($db->quoteName('value'))->from($db->quoteName('#__uxstudio_reviews_meta'))->where($db->quoteName('name') . ' = :n')->bind(':n', $name))->loadResult();

        return $v === null ? $default : (string) $v;
    }

    public function setMeta(string $name, string $value): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $upd = $db->createQuery()->update($db->quoteName('#__uxstudio_reviews_meta'))
            ->set($db->quoteName('value') . ' = :v')->set($db->quoteName('updated_at') . ' = :u')->where($db->quoteName('name') . ' = :n')
            ->bind(':v', $value)->bind(':u', $now)->bind(':n', $name);
        $db->setQuery($upd)->execute();
        if ($db->getAffectedRows() > 0 || $this->getMeta($name, "\0") !== "\0") {
            return;
        }
        $ins = $db->createQuery()->insert($db->quoteName('#__uxstudio_reviews_meta'))
            ->columns($db->quoteName(['name', 'value', 'updated_at']))->values(':n, :v, :u')->bind(':n', $name)->bind(':v', $value)->bind(':u', $now);
        $db->setQuery($ins)->execute();
    }

    /** @param array<string,mixed> $row */
    public static function format(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'source'      => (string) $row['source'],
            'origin'      => (string) ($row['origin'] ?? ''),
            'author'      => (string) $row['author'],
            'rating'      => (int) $row['rating'],
            'text'        => (string) ($row['text'] ?? ''),
            'review_date' => !empty($row['review_date']) ? (string) $row['review_date'] : null,
            'imported_at' => (string) ($row['imported_at'] ?? ''),
            'visible'     => (bool) $row['visible'],
        ];
    }
}

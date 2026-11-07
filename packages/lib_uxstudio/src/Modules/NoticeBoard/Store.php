<?php

namespace Ux1\UxStudio\Modules\NoticeBoard;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ApiException;

\defined('_JEXEC') or die;

/**
 * Data layer of the Notice Board: notices, categories and e-mail subscriptions (all queries parameterised).
 * Dates (publish / expiry) are plain Y-m-d in the time zone of the site.
 */
final class Store
{
    private const T_NOTICES = '#__uxstudio_notice_board';
    private const T_CATS    = '#__uxstudio_notice_board_categories';
    private const T_SUBS    = '#__uxstudio_notice_board_subscriptions';

    /** Attachment extensions that may be published (no executable or script types). */
    private const FILE_TYPES = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'rtf' => 'application/rtf', 'zip' => 'application/zip',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];

    /** @var (callable():string)|null */
    private $rootProvider = null;

    public function __construct(private DatabaseInterface $db, private \DateTimeZone $tz)
    {
    }

    public function setRootProvider(callable $provider): void
    {
        $this->rootProvider = $provider;
    }

    public function root(): string
    {
        return $this->rootProvider !== null ? (string) ($this->rootProvider)() : Uri::root();
    }

    public function getMeta(string $key): string
    {
        $db = $this->db;

        return (string) $db->setQuery($db->createQuery()->select($db->quoteName('v'))->from($db->quoteName('#__uxstudio_notice_board_meta'))->where($db->quoteName('k') . ' = :k')->bind(':k', $key))->loadResult();
    }

    public function setMeta(string $key, string $value): void
    {
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__uxstudio_notice_board_meta') . ' (' . $db->quoteName('k') . ', ' . $db->quoteName('v') . ') VALUES (' . $db->quote($key) . ', ' . $db->quote($value)
            . ') ON DUPLICATE KEY UPDATE ' . $db->quoteName('v') . ' = ' . $db->quote($value)
        )->execute();
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', $this->tz))->format('Y-m-d');
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /* ---------------------------------------------------------------- validation helpers */

    public static function cleanDate(mixed $value): ?string
    {
        $value = trim((string) (\is_scalar($value) ? $value : ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    public static function slug(string $name): string
    {
        return mb_substr(ApplicationHelper::stringURLSafe(trim(strip_tags($name))), 0, 190);
    }

    /**
     * Attachment paths of the Joomla media folder (images/...): the file must exist below /images and have a whitelisted type.
     *
     * @return array<int,string> relative paths
     */
    public static function cleanAttachments(mixed $raw): array
    {
        $out  = [];
        $root = realpath(JPATH_ROOT . '/images');
        foreach (\is_array($raw) ? $raw : [] as $item) {
            $path = \is_array($item) ? (string) ($item['path'] ?? '') : (string) (\is_scalar($item) ? $item : '');
            $path = trim(explode('#', explode('?', $path)[0])[0]);
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if ($root === false || $path === '' || str_contains($path, '..') || !str_starts_with($path, 'images/')) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!isset(self::FILE_TYPES[$ext])) {
                continue;
            }
            $real = realpath(JPATH_ROOT . '/' . $path);
            if ($real === false || !is_file($real) || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/')) {
                continue;
            }
            if (!\in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return \array_slice($out, 0, 20);
    }

    /** @return array{path:string,url:string,abs_url:string,filename:string,mime:string,size:int} */
    private function describeFile(string $path): array
    {
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $full = JPATH_ROOT . '/' . $path;
        $enc  = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url  = Uri::root(true) . '/' . $enc;

        return [
            'path'     => $path,
            'url'      => $url,
            'abs_url'  => $this->root() . $enc,
            'filename' => basename($path),
            'mime'     => self::FILE_TYPES[$ext] ?? 'application/octet-stream',
            'size'     => is_file($full) ? (int) filesize($full) : 0,
        ];
    }

    /* ---------------------------------------------------------------- notices */

    private function shapeNotice(array $r): array
    {
        $paths = json_decode((string) ($r['attachments'] ?? ''), true);
        $files = [];
        foreach (\is_array($paths) ? $paths : [] as $p) {
            if (\is_string($p) && ($clean = self::cleanAttachments([$p])) !== []) {
                $files[] = $this->describeFile($clean[0]);
            } elseif (\is_string($p)) {
                $files[] = ['path' => $p, 'url' => '', 'abs_url' => '', 'filename' => basename($p), 'mime' => '', 'size' => 0, 'missing' => true];
            }
        }

        return [
            'id'           => (int) $r['id'],
            'created_at'   => (string) $r['created_at'],
            'updated_at'   => (string) ($r['updated_at'] ?? ''),
            'title'        => (string) $r['title'],
            'body'         => (string) ($r['body'] ?? ''),
            'category'     => (string) $r['category'],
            'reference'    => (string) $r['reference'],
            'attachments'  => $files,
            'publish_date' => (string) ($r['publish_date'] ?? ''),
            'expiry_date'  => (string) ($r['expiry_date'] ?? ''),
            'is_archived'  => (bool) $r['is_archived'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listNotices(bool $includeArchived = true): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('*')->from($db->quoteName(self::T_NOTICES));
        if (!$includeArchived) {
            $q->where($db->quoteName('is_archived') . ' = 0');
        }
        $q->order($db->quoteName('is_archived') . ' ASC, ' . $db->quoteName('publish_date') . ' DESC, ' . $db->quoteName('id') . ' DESC');

        return array_map([$this, 'shapeNotice'], $db->setQuery($q)->loadAssocList() ?: []);
    }

    public function getNotice(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::T_NOTICES))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return \is_array($row) ? $this->shapeNotice($row) : null;
    }

    /** Detail for the public: published (date reached); archived ones stay readable. */
    public function getPublicNotice(int $id): ?array
    {
        $n = $this->getNotice($id);
        if ($n === null || ($n['publish_date'] !== '' && $n['publish_date'] > $this->today())) {
            return null;
        }

        return $n;
    }

    /**
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $old
     * @return array<string,mixed>
     */
    private function validateNotice(array $in, ?array $old): array
    {
        $has = static fn (string $k): bool => \array_key_exists($k, $in);
        $get = static fn (string $k, mixed $d) => $has($k) ? $in[$k] : ($old[$k] ?? $d);

        $title = mb_substr(trim(strip_tags((string) (\is_scalar($get('title', '')) ? $get('title', '') : ''))), 0, 255);
        if ($title === '') {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_TITLE'), 422, 'invalid_value');
        }
        $category = (string) $get('category', '');
        $category = $category === '' ? '' : self::slug($category);
        if ($category !== '' && $category !== (string) ($old['category'] ?? '') && !isset($this->categoryNames()[$category])) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_CATEGORY_UNKNOWN'), 422, 'invalid_value');
        }

        $paths = $has('attachments') ? self::cleanAttachments($in['attachments']) : array_map(static fn (array $f): string => (string) $f['path'], (array) ($old['attachments'] ?? []));
        $publish = $has('publish_date') ? self::cleanDate($in['publish_date']) : self::cleanDate($old['publish_date'] ?? '');
        if ($publish === null && $old === null) {
            $publish = $this->today();
        }

        return [
            'title'        => $title,
            'body'         => InputFilter::getInstance([], [], 1, 1)->clean((string) (\is_scalar($get('body', '')) ? $get('body', '') : ''), 'html'),
            'category'     => $category,
            'reference'    => mb_substr(trim(strip_tags((string) (\is_scalar($get('reference', '')) ? $get('reference', '') : ''))), 0, 190),
            'attachments'  => (string) json_encode(array_values($paths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'publish_date' => $publish,
            'expiry_date'  => $has('expiry_date') ? self::cleanDate($in['expiry_date']) : self::cleanDate($old['expiry_date'] ?? ''),
            'is_archived'  => filter_var($get('is_archived', false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    public function createNotice(array $in): array
    {
        $v   = $this->validateNotice($in, null);
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName(self::T_NOTICES))
                ->columns($db->quoteName(['created_at', 'updated_at', 'title', 'body', 'category', 'reference', 'attachments', 'publish_date', 'expiry_date', 'is_archived']))
                ->values(':c, :u, :t, :b, :cat, :ref, :att, :pd, :ed, :arch')
                ->bind(':c', $now)->bind(':u', $now)->bind(':t', $v['title'])->bind(':b', $v['body'])->bind(':cat', $v['category'])->bind(':ref', $v['reference'])
                ->bind(':att', $v['attachments'])->bind(':pd', $v['publish_date'], $v['publish_date'] === null ? ParameterType::NULL : ParameterType::STRING)
                ->bind(':ed', $v['expiry_date'], $v['expiry_date'] === null ? ParameterType::NULL : ParameterType::STRING)->bind(':arch', $v['is_archived'], ParameterType::INTEGER)
        )->execute();

        return $this->getNotice((int) $db->insertid()) ?? [];
    }

    public function updateNotice(int $id, array $in): array
    {
        $old = $this->getNotice($id);
        if ($old === null) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $v   = $this->validateNotice($in, $old);
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::T_NOTICES))
                ->set($db->quoteName('updated_at') . ' = :u')->set($db->quoteName('title') . ' = :t')->set($db->quoteName('body') . ' = :b')
                ->set($db->quoteName('category') . ' = :cat')->set($db->quoteName('reference') . ' = :ref')->set($db->quoteName('attachments') . ' = :att')
                ->set($db->quoteName('publish_date') . ' = :pd')->set($db->quoteName('expiry_date') . ' = :ed')->set($db->quoteName('is_archived') . ' = :arch')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':u', $now)->bind(':t', $v['title'])->bind(':b', $v['body'])->bind(':cat', $v['category'])->bind(':ref', $v['reference'])
                ->bind(':att', $v['attachments'])->bind(':pd', $v['publish_date'], $v['publish_date'] === null ? ParameterType::NULL : ParameterType::STRING)
                ->bind(':ed', $v['expiry_date'], $v['expiry_date'] === null ? ParameterType::NULL : ParameterType::STRING)->bind(':arch', $v['is_archived'], ParameterType::INTEGER)
                ->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $this->getNotice($id) ?? [];
    }

    public function deleteNotice(int $id): void
    {
        if ($this->getNotice($id) === null) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::T_NOTICES))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /**
     * Public, filtered and paginated query: only notices whose publish date has been reached.
     *
     * @param array{archived?:bool,search?:string,categories?:array,date_from?:string,date_to?:string,page?:int,per_page?:int} $args
     * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
     */
    public function queryPublic(array $args): array
    {
        $db      = $this->db;
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $arch    = !empty($args['archived']) ? 1 : 0;
        $today   = $this->today();

        $build = function ($q) use ($db, $args, $arch, $today) {
            $q->where($db->quoteName('is_archived') . ' = :arch')->bind(':arch', $arch, ParameterType::INTEGER);
            $q->where('(' . $db->quoteName('publish_date') . ' IS NULL OR ' . $db->quoteName('publish_date') . ' <= :today)')->bind(':today', $today);
            $search = trim((string) ($args['search'] ?? ''));
            if ($search !== '') {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $q->where('(' . $db->quoteName('title') . ' LIKE :s1 OR ' . $db->quoteName('body') . ' LIKE :s2 OR ' . $db->quoteName('reference') . ' LIKE :s3)')
                    ->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like);
            }
            $slugs = array_values(array_filter(array_map(static fn ($s): string => self::slug((string) $s), (array) ($args['categories'] ?? []))));
            if ($slugs !== []) {
                $q->whereIn($db->quoteName('category'), $slugs, ParameterType::STRING);
            }
            if (($from = self::cleanDate($args['date_from'] ?? '')) !== null) {
                $q->where($db->quoteName('publish_date') . ' >= :df')->bind(':df', $from);
            }
            if (($to = self::cleanDate($args['date_to'] ?? '')) !== null) {
                $q->where($db->quoteName('publish_date') . ' <= :dt')->bind(':dt', $to);
            }

            return $q;
        };

        $total = (int) $db->setQuery($build($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::T_NOTICES))))->loadResult();
        $rows  = $db->setQuery(
            $build($db->createQuery()->select('*')->from($db->quoteName(self::T_NOTICES)))
                ->order($db->quoteName('publish_date') . ' DESC, ' . $db->quoteName('id') . ' DESC'),
            ($page - 1) * $perPage,
            $perPage
        )->loadAssocList() ?: [];

        return ['items' => array_map([$this, 'shapeNotice'], $rows), 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage)), 'page' => $page];
    }

    /** @return array{active:int,archived:int} published notices only */
    public function countPublic(): array
    {
        $db    = $this->db;
        $today = $this->today();
        $rows  = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('is_archived'), 'COUNT(*) AS c'])->from($db->quoteName(self::T_NOTICES))
                ->where('(' . $db->quoteName('publish_date') . ' IS NULL OR ' . $db->quoteName('publish_date') . ' <= :today)')->bind(':today', $today)
                ->group($db->quoteName('is_archived'))
        )->loadAssocList() ?: [];
        $out = ['active' => 0, 'archived' => 0];
        foreach ($rows as $r) {
            $out[(int) $r['is_archived'] === 1 ? 'archived' : 'active'] = (int) $r['c'];
        }

        return $out;
    }

    /** Archives notices whose expiry date is past. Returns the number archived. */
    public function archiveExpired(): int
    {
        $db    = $this->db;
        $today = $this->today();
        $now   = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::T_NOTICES))
                ->set($db->quoteName('is_archived') . ' = 1')->set($db->quoteName('updated_at') . ' = :now')
                ->where($db->quoteName('is_archived') . ' = 0')->where($db->quoteName('expiry_date') . ' IS NOT NULL')->where($db->quoteName('expiry_date') . ' < :today')
                ->bind(':now', $now)->bind(':today', $today)
        )->execute();

        return $db->getAffectedRows();
    }

    /** Deletes archived notices whose expiry date is older than $days. Returns the number deleted. */
    public function purgeArchived(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $db     = $this->db;
        $cutoff = (new \DateTimeImmutable('now', $this->tz))->modify('-' . $days . ' days')->format('Y-m-d');
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName(self::T_NOTICES))
                ->where($db->quoteName('is_archived') . ' = 1')->where($db->quoteName('expiry_date') . ' IS NOT NULL')->where($db->quoteName('expiry_date') . ' < :c')
                ->bind(':c', $cutoff)
        )->execute();

        return $db->getAffectedRows();
    }

    /** @return array<int,array<string,mixed>> published, not archived, not yet announced by e-mail */
    public function dueForNotification(): array
    {
        $db    = $this->db;
        $today = $this->today();
        $rows  = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::T_NOTICES))
                ->where($db->quoteName('notified') . ' = 0')->where($db->quoteName('is_archived') . ' = 0')
                ->where('(' . $db->quoteName('publish_date') . ' IS NULL OR ' . $db->quoteName('publish_date') . ' <= :today)')->bind(':today', $today)
                ->order($db->quoteName('id') . ' ASC')
        )->loadAssocList() ?: [];

        return array_map([$this, 'shapeNotice'], $rows);
    }

    public function markNotified(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->update($db->quoteName(self::T_NOTICES))->set($db->quoteName('notified') . ' = 1')->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /* ---------------------------------------------------------------- categories */

    /** @return array<int,array{id:int,name:string,slug:string,sort_order:int,count:int}> */
    public function listCategories(): array
    {
        $db     = $this->db;
        $rows   = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'name', 'slug', 'sort_order']))->from($db->quoteName(self::T_CATS))
                ->order($db->quoteName('sort_order') . ' ASC, ' . $db->quoteName('name') . ' ASC')
        )->loadAssocList() ?: [];
        $counts = [];
        foreach ($db->setQuery($db->createQuery()->select([$db->quoteName('category'), 'COUNT(*) AS c'])->from($db->quoteName(self::T_NOTICES))->group($db->quoteName('category')))->loadAssocList() ?: [] as $r) {
            $counts[(string) $r['category']] = (int) $r['c'];
        }

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'slug'       => (string) $r['slug'],
            'sort_order' => (int) $r['sort_order'],
            'count'      => $counts[(string) $r['slug']] ?? 0,
        ], $rows);
    }

    /** @return array<string,string> slug => name */
    public function categoryNames(): array
    {
        $out = [];
        foreach ($this->listCategories() as $c) {
            $out[$c['slug']] = $c['name'];
        }

        return $out;
    }

    public function createCategory(string $name, int $sort): array
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 190);
        $slug = self::slug($name);
        if ($name === '' || $slug === '') {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_CATEGORY_NAME'), 422, 'invalid_value');
        }
        if (isset($this->categoryNames()[$slug])) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_CATEGORY_EXISTS'), 409, 'exists');
        }
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName(self::T_CATS))->columns($db->quoteName(['name', 'slug', 'sort_order']))->values(':n, :s, :o')
                ->bind(':n', $name)->bind(':s', $slug)->bind(':o', $sort, ParameterType::INTEGER)
        )->execute();

        return ['id' => (int) $db->insertid(), 'name' => $name, 'slug' => $slug, 'sort_order' => $sort, 'count' => 0];
    }

    public function updateCategory(int $id, array $in): array
    {
        $db  = $this->db;
        $row = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'name', 'sort_order']))->from($db->quoteName(self::T_CATS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->loadAssoc();
        if (!\is_array($row)) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $name = \array_key_exists('name', $in) ? mb_substr(trim(strip_tags((string) (\is_scalar($in['name']) ? $in['name'] : ''))), 0, 190) : (string) $row['name'];
        $sort = \array_key_exists('sort_order', $in) ? (int) $in['sort_order'] : (int) $row['sort_order'];
        if ($name === '') {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_CATEGORY_NAME'), 422, 'invalid_value');
        }
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::T_CATS))->set($db->quoteName('name') . ' = :n')->set($db->quoteName('sort_order') . ' = :o')
                ->where($db->quoteName('id') . ' = :id')->bind(':n', $name)->bind(':o', $sort, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
        foreach ($this->listCategories() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return [];
    }

    public function deleteCategory(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::T_CATS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        if ($db->getAffectedRows() === 0) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }
    }

    /* ---------------------------------------------------------------- subscriptions */

    /** @return array<int,array<string,mixed>> */
    public function listSubscriptions(): array
    {
        $db = $this->db;

        return array_map(static function (array $r): array {
            $cats = json_decode((string) ($r['categories'] ?? ''), true);

            return [
                'id'         => (int) $r['id'],
                'created_at' => (string) $r['created_at'],
                'email'      => (string) $r['email'],
                'confirmed'  => (bool) $r['confirmed'],
                'categories' => \is_array($cats) ? array_values(array_map('strval', $cats)) : [],
            ];
        }, $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'created_at', 'email', 'confirmed', 'categories']))->from($db->quoteName(self::T_SUBS))->order($db->quoteName('id') . ' DESC')
        )->loadAssocList() ?: []);
    }

    public function countSubscribers(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select([$db->quoteName('confirmed'), 'COUNT(*) AS c'])->from($db->quoteName(self::T_SUBS))->group($db->quoteName('confirmed')))->loadAssocList() ?: [];
        $out  = ['confirmed' => 0, 'pending' => 0];
        foreach ($rows as $r) {
            $out[(int) $r['confirmed'] === 1 ? 'confirmed' : 'pending'] = (int) $r['c'];
        }

        return $out;
    }

    public function deleteSubscription(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::T_SUBS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        if ($db->getAffectedRows() === 0) {
            throw new ApiException(Text::_('UXS_NOTICE_BOARD_ERR_NOT_FOUND'), 404, 'not_found');
        }
    }

    /** @param array<int,mixed> $raw @return array<int,string> only existing category slugs */
    public function cleanCategorySlugs(mixed $raw): array
    {
        $valid = array_keys($this->categoryNames());
        $out   = [];
        foreach (\is_array($raw) ? $raw : [] as $slug) {
            $slug = self::slug((string) (\is_scalar($slug) ? $slug : ''));
            if ($slug !== '' && \in_array($slug, $valid, true) && !\in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    public function findSubscription(string $email): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::T_SUBS))->where($db->quoteName('email') . ' = :e')->bind(':e', $email))->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    public function insertSubscription(string $email, array $categories, string $confirmToken, string $unsubscribeToken): int
    {
        $db   = $this->db;
        $now  = Factory::getDate()->toSql();
        $json = (string) json_encode($categories);
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName(self::T_SUBS))
                ->columns($db->quoteName(['created_at', 'email', 'confirmed', 'confirm_token', 'unsubscribe_token', 'categories']))->values(':c, :e, 0, :ct, :ut, :cats')
                ->bind(':c', $now)->bind(':e', $email)->bind(':ct', $confirmToken)->bind(':ut', $unsubscribeToken)->bind(':cats', $json)
        )->execute();

        return (int) $db->insertid();
    }

    /** Updates a subscription row: any of categories (JSON list), pending_categories (list|null), confirm_token, confirm_sent. */
    public function patchSubscription(int $id, array $fields): void
    {
        $db = $this->db;
        $q  = $db->createQuery()->update($db->quoteName(self::T_SUBS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        $n  = 0;
        if (\array_key_exists('categories', $fields)) {
            $cats = (string) json_encode($fields['categories']);
            $q->set($db->quoteName('categories') . ' = :cats')->bind(':cats', $cats);
            $n++;
        }
        if (\array_key_exists('pending_categories', $fields)) {
            $pending = (string) json_encode($fields['pending_categories']);
            $q->set($db->quoteName('pending_categories') . ' = :pend')->bind(':pend', $pending);
            $n++;
        }
        if (\array_key_exists('confirm_token', $fields)) {
            $token = (string) $fields['confirm_token'];
            $q->set($db->quoteName('confirm_token') . ' = :tok')->bind(':tok', $token);
            $n++;
        }
        if (!empty($fields['confirm_sent'])) {
            $sent = gmdate('Y-m-d H:i:s');
            $q->set($db->quoteName('confirm_sent_at') . ' = :sent')->bind(':sent', $sent);
            $n++;
        }
        if ($n > 0) {
            $db->setQuery($q)->execute();
        }
    }

    /** Confirms a new subscription or applies a category change parked for an already confirmed address. */
    public function confirm(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::T_SUBS))
                ->set($db->quoteName('confirmed') . ' = 1')
                ->set($db->quoteName('categories') . ' = COALESCE(' . $db->quoteName('pending_categories') . ', ' . $db->quoteName('categories') . ')')
                ->set($db->quoteName('pending_categories') . ' = NULL')
                ->where($db->quoteName('confirm_token') . ' = :t')->bind(':t', $token)
        )->execute();

        // A repeated click (mail scanners open links first) updates nothing, yet the address is confirmed.
        return $db->getAffectedRows() > 0 || (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::T_SUBS))->where($db->quoteName('confirm_token') . ' = :t2')->bind(':t2', $token)
        )->loadResult() > 0;
    }

    public function unsubscribe(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::T_SUBS))->where($db->quoteName('unsubscribe_token') . ' = :t')->bind(':t', $token))->execute();

        return $db->getAffectedRows() > 0;
    }

    /** @return array<int,array{email:string,unsubscribe_token:string,categories:array<int,string>}> confirmed subscribers */
    public function confirmedSubscribers(): array
    {
        $db = $this->db;

        return array_map(static function (array $r): array {
            $cats = json_decode((string) ($r['categories'] ?? ''), true);

            return ['email' => (string) $r['email'], 'unsubscribe_token' => (string) $r['unsubscribe_token'], 'categories' => \is_array($cats) ? array_map('strval', $cats) : []];
        }, $db->setQuery(
            $db->createQuery()->select($db->quoteName(['email', 'unsubscribe_token', 'categories']))->from($db->quoteName(self::T_SUBS))->where($db->quoteName('confirmed') . ' = 1')
        )->loadAssocList() ?: []);
    }
}

<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * What the module may publish: articles and categories an anonymous visitor can see, minus the exclusions from the
 * settings, and the URLs of them. Everything that reaches AI bots goes through here, so a draft, an expired article or
 * a registered-only article can never leak into llms.txt or a markdown response.
 */
final class Content
{
    /** @var int[]|null */
    private ?array $levels = null;

    /** @var array<int,array{0:int,1:int}>|null lft/rgt of excluded category subtrees */
    private ?array $excludedTrees = null;

    public function __construct(private ModuleContext $ctx)
    {
    }

    private function db(): DatabaseInterface
    {
        return $this->ctx->db();
    }

    /** @return int[] view levels of an anonymous visitor */
    private function guestLevels(): array
    {
        return $this->levels ??= array_map('intval', Access::getAuthorisedViewLevels(0)) ?: [1];
    }

    /** @return int[] */
    public static function idList(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []), static fn (int $i): bool => $i > 0)));
    }

    /** @return int[] */
    public function excludedArticleIds(): array
    {
        return self::idList((string) $this->ctx->setting('exclude_ids', ''));
    }

    /** @return array<int,array{0:int,1:int}> */
    private function excludedTrees(): array
    {
        if ($this->excludedTrees !== null) {
            return $this->excludedTrees;
        }
        $ids = self::idList((string) $this->ctx->setting('exclude_categories', ''));
        if (!$ids) {
            return $this->excludedTrees = [];
        }
        $db   = $this->db();
        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['lft', 'rgt']))->from($db->quoteName('#__categories'))
                ->whereIn($db->quoteName('id'), $ids)->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
        )->loadRowList() ?: [];

        return $this->excludedTrees = array_map(static fn (array $r): array => [(int) $r[0], (int) $r[1]], $rows);
    }

    /**
     * SQL conditions for "an anonymous visitor can read this article" over the given aliases of #__content and
     * #__categories ($sfx keeps bound parameter names unique when used inside a sub-query). Integers are inlined.
     *
     * @return string[]
     */
    private function visibleConditions(QueryInterface $q, string $a, string $c, string $sfx): array
    {
        $db     = $this->db();
        $now    = Factory::getDate()->toSql();
        $levels = implode(',', $this->guestLevels());
        $p      = 'p' . $sfx;
        $q->bind(':vn1' . $sfx, $now)->bind(':vn2' . $sfx, $now);
        $out = [
            "$a.state = 1",
            "$c.published = 1",
            "$c.extension = " . $db->quote('com_content'),
            "$a.access IN ($levels)",
            "$c.access IN ($levels)",
            "($a.publish_up IS NULL OR $a.publish_up <= :vn1$sfx)",
            "($a.publish_down IS NULL OR $a.publish_down >= :vn2$sfx)",
            // A published category below an unpublished parent is not reachable on the site.
            "NOT EXISTS (SELECT 1 FROM " . $db->quoteName('#__categories', $p) . " WHERE $p.lft < $c.lft AND $p.rgt > $c.rgt AND $p.extension = " . $db->quote('com_content') . " AND $p.published <> 1)",
        ];
        $ids = $this->excludedArticleIds();
        if ($ids) {
            $out[] = "$a.id NOT IN (" . implode(',', $ids) . ')';
        }
        foreach ($this->excludedTrees() as [$lft, $rgt]) {
            $out[] = "NOT ($c.lft >= $lft AND $c.rgt <= $rgt)";
        }

        return $out;
    }

    /** Adds the "visible to everyone" and exclusion conditions; the query must select from #__content a JOIN #__categories c. */
    private function restrict(QueryInterface $q): void
    {
        foreach ($this->visibleConditions($q, 'a', 'c', '') as $cond) {
            $q->where($cond);
        }
    }

    private function articleQuery(bool $withText): QueryInterface
    {
        $db   = $this->db();
        $cols = ['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.metadesc', 'a.created', 'a.modified', 'a.publish_up', 'a.publish_down', 'a.created_by_alias', 'a.featured', 'a.state'];
        if ($withText) {
            $cols[] = 'a.introtext';
            $cols[] = 'a.fulltext';
        }
        $q = $db->createQuery()
            ->select($db->quoteName($cols))
            ->select($db->quoteName(['c.title', 'c.path', 'c.lft'], ['cat_title', 'cat_path', 'cat_lft']))
            ->select($db->quoteName('u.name', 'author'))
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = a.created_by');
        $this->restrict($q);

        return $q;
    }

    /** One article visible to everybody, or null (unpublished, expired, restricted, excluded). */
    public function article(int $id): ?array
    {
        $db  = $this->db();
        $row = $db->setQuery($this->articleQuery(true)->where($db->quoteName('a.id') . ' = :aid')->bind(':aid', $id, ParameterType::INTEGER))->loadAssoc();

        return $row ?: null;
    }

    /**
     * Visible articles without their text, newest first (or by category).
     *
     * @return array<int,array<string,mixed>>
     */
    public function articles(int $limit, string $order = 'date', int $catId = 0, bool $withText = false): array
    {
        $db = $this->db();
        $q  = $this->articleQuery($withText);
        if ($catId > 0) {
            $q->where($db->quoteName('a.catid') . ' = :cid')->bind(':cid', $catId, ParameterType::INTEGER);
        }
        if ($order === 'category') {
            $q->order('c.lft ASC, COALESCE(a.publish_up, a.created) DESC, a.id DESC');
        } else {
            $q->order('COALESCE(a.publish_up, a.created) DESC, a.id DESC');
        }

        return $db->setQuery($q, 0, max(1, $limit))->loadAssocList() ?: [];
    }

    /**
     * One page of visible articles (title search done in SQL) and the total number of matches.
     *
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function articlePage(int $offset, int $limit, string $search = ''): array
    {
        $db = $this->db();
        $cq = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid');
        $this->restrict($cq);
        $q = $this->articleQuery(false)->order('COALESCE(a.publish_up, a.created) DESC, a.id DESC');
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\%_') . '%';
            $cq->where($db->quoteName('a.title') . ' LIKE :sq')->bind(':sq', $like);
            $q->where($db->quoteName('a.title') . ' LIKE :sq')->bind(':sq', $like);
        }
        $total = (int) $db->setQuery($cq)->loadResult();

        return [$db->setQuery($q, max(0, $offset), max(1, $limit))->loadAssocList() ?: [], $total];
    }

    /** Number of published articles and the newest change: detects a stale aggregate even when no save event was seen. */
    public function fingerprint(): string
    {
        $db = $this->db();
        $q  = $db->createQuery()->select('COUNT(*) AS n, MAX(a.modified) AS m, SUM(a.id) AS s')
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid');
        $this->restrict($q);
        $row = $db->setQuery($q)->loadAssoc() ?: [];

        return ($row['n'] ?? 0) . ':' . ($row['m'] ?? '') . ':' . ($row['s'] ?? 0);
    }

    public function eligibleCount(): int
    {
        return (int) explode(':', $this->fingerprint())[0];
    }

    /** @return array<string,mixed>|null a category an anonymous visitor can open, or null */
    public function category(int $id): ?array
    {
        $db  = $this->db();
        $q   = $db->createQuery()->select($db->quoteName(['c.id', 'c.title', 'c.alias', 'c.description', 'c.metadesc', 'c.language', 'c.path', 'c.lft', 'c.rgt', 'c.parent_id', 'c.modified_time']))
            ->from($db->quoteName('#__categories', 'c'))
            ->where($db->quoteName('c.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        $this->restrictCategory($q);

        return $db->setQuery($q)->loadAssoc() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        $db = $this->db();
        $q  = $db->createQuery()->select($db->quoteName(['c.id', 'c.title', 'c.alias', 'c.description', 'c.metadesc', 'c.language', 'c.path', 'c.lft', 'c.level', 'c.parent_id']))
            ->from($db->quoteName('#__categories', 'c'))->order('c.lft ASC');
        $this->restrictCategory($q);
        // Only categories that hold at least one article an anonymous visitor can read (directly or below).
        $sub = implode(' AND ', $this->visibleConditions($q, 'x', 'xc', 'x'));
        $q->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__content', 'x') . ' INNER JOIN ' . $db->quoteName('#__categories', 'xc') . ' ON xc.id = x.catid'
            . ' WHERE xc.lft >= c.lft AND xc.rgt <= c.rgt AND ' . $sub . ')');

        return $db->setQuery($q)->loadAssocList() ?: [];
    }

    private function restrictCategory(QueryInterface $q): void
    {
        $db = $this->db();
        $q->where($db->quoteName('c.published') . ' = 1')
            ->where($db->quoteName('c.extension') . ' = ' . $db->quote('com_content'))
            ->whereIn($db->quoteName('c.access'), $this->guestLevels())
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'p') . ' WHERE p.lft < c.lft AND p.rgt > c.rgt AND p.extension = ' . $db->quote('com_content') . ' AND p.published <> 1)');
        foreach ($this->excludedTrees() as $i => [$lft, $rgt]) {
            $q->where('NOT (c.lft >= :clft' . $i . ' AND c.rgt <= :crgt' . $i . ')')
                ->bind(':clft' . $i, $lft, ParameterType::INTEGER)->bind(':crgt' . $i, $rgt, ParameterType::INTEGER);
        }
    }

    /** @return string[] titles of the published public tags of an article */
    public function tags(int $articleId): array
    {
        $db = $this->db();
        try {
            $q = $db->createQuery()->select($db->quoteName('t.title'))
                ->from($db->quoteName('#__contentitem_tag_map', 'm'))
                ->join('INNER', $db->quoteName('#__tags', 't') . ' ON t.id = m.tag_id')
                ->where($db->quoteName('m.type_alias') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('m.content_item_id') . ' = :aid')->bind(':aid', $articleId, ParameterType::INTEGER)
                ->where($db->quoteName('t.published') . ' = 1')
                ->whereIn($db->quoteName('t.access'), $this->guestLevels());

            return array_map('strval', $db->setQuery($q)->loadColumn() ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Public menu items of the selected menus (all site menus when none is selected).
     *
     * @return array<int,array{id:int,title:string,home:bool,menu:string}>
     */
    public function menuItems(): array
    {
        $db       = $this->db();
        $selected = array_values(array_filter(array_map('strval', (array) $this->ctx->setting('menu_types', []))));
        $now      = Factory::getDate()->toSql();
        $q        = $db->createQuery()
            ->select($db->quoteName(['m.id', 'm.title', 'm.home', 'm.menutype']))
            ->select($db->quoteName('t.title', 'menu_title'))
            ->from($db->quoteName('#__menu', 'm'))
            ->join('INNER', $db->quoteName('#__menu_types', 't') . ' ON t.menutype = m.menutype AND t.client_id = 0')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->where($db->quoteName('m.published') . ' = 1')
            ->where($db->quoteName('m.type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('m.parent_id') . ' > 0')
            ->whereIn($db->quoteName('m.access'), $this->guestLevels())
            ->where('(m.publish_up IS NULL OR m.publish_up <= :mn1)')->where('(m.publish_down IS NULL OR m.publish_down >= :mn2)')
            ->bind(':mn1', $now)->bind(':mn2', $now)
            ->order('t.id ASC, m.lft ASC');
        if ($selected) {
            $q->whereIn($db->quoteName('m.menutype'), $selected, ParameterType::STRING);
        }
        $out = [];
        foreach ($db->setQuery($q, 0, 300)->loadAssocList() ?: [] as $r) {
            $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'home' => (int) $r['home'] === 1, 'menu' => (string) $r['menu_title']];
        }

        return $out;
    }

    /** @return array<string,string> menutype => title (for the settings form) */
    public function menuTypeOptions(): array
    {
        $db = $this->db();
        try {
            $rows = $db->setQuery($db->createQuery()->select($db->quoteName(['menutype', 'title']))->from($db->quoteName('#__menu_types'))
                ->where($db->quoteName('client_id') . ' = 0')->order('id ASC'))->loadAssocList() ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['menutype']] = (string) $r['title'];
        }

        return $out;
    }

    // ---- URLs -----------------------------------------------------------------------------------------------------

    public function root(): string
    {
        return Uri::root();
    }

    /**
     * Public URL of a virtual file (llms.txt ...). Without working URL rewriting (no .htaccess / sef_rewrite off) only
     * "index.php/<name>" reaches Joomla, so that form is used then; the bare name would be a web server 404.
     */
    public function virtualUrl(string $name): string
    {
        return $this->root() . ($this->rewriteActive() ? '' : 'index.php/') . $name;
    }

    public function rewriteActive(): bool
    {
        $app = $this->ctx->app();

        return (bool) $app->get('sef', 1) && (bool) $app->get('sef_rewrite', 0) && is_file(JPATH_ROOT . '/.htaccess');
    }

    /** Site root that links are trusted for: "live_site" when configured, otherwise the root an administrator was seen on. */
    public function canonicalRoot(): string
    {
        $live = trim((string) $this->ctx->app()->get('live_site', ''));
        if ($live !== '') {
            return rtrim($live, '/') . '/';
        }

        return (string) $this->ctx->store()->get('canonical_root', '');
    }

    /** True when the root of this request is the canonical one, i.e. its Host header is not forged. */
    public function rootTrusted(): bool
    {
        $canon = $this->canonicalRoot();

        return $canon !== '' && strcasecmp($canon, $this->root()) === 0;
    }

    /** Remembers the root of an authenticated administrator request (only called from the administrator API). */
    public function rememberRoot(): void
    {
        if (trim((string) $this->ctx->app()->get('live_site', '')) === '' && $this->urlsAvailable()) {
            $store = $this->ctx->store();
            if ($store->get('canonical_root', '') !== $this->root()) {
                $store->set('canonical_root', $this->root());
            }
        }
    }

    /**
     * Whether absolute URLs can be built here. A web request always knows its host; the command line (system cron,
     * scheduler:run) does not, unless "live_site" is set in configuration.php. Documents with wrong links must never
     * reach the cache.
     */
    public function urlsAvailable(): bool
    {
        return PHP_SAPI !== 'cli' || trim((string) $this->ctx->app()->get('live_site', '')) !== '';
    }

    /** Absolute public URL of an article. */
    public function articleUrl(array $a): string
    {
        $route = RouteHelper::getArticleRoute((int) $a['id'] . ':' . (string) ($a['alias'] ?? ''), (int) $a['catid'], (string) ($a['language'] ?? '*'));

        return $this->absolute($route);
    }

    public function categoryUrl(array $c): string
    {
        return $this->absolute(RouteHelper::getCategoryRoute((int) $c['id'] . ':' . (string) ($c['alias'] ?? ''), (string) ($c['language'] ?? '*')));
    }

    public function menuUrl(int $itemId): string
    {
        return $this->absolute('index.php?Itemid=' . $itemId);
    }

    private function absolute(string $link): string
    {
        $url = Route::link('site', $link, false, Route::TLS_IGNORE, true);
        // Called from the administrator, the router can leave the admin folder in the path.
        $url = str_replace('/administrator/', '/', $url);
        // The site-wide "index.php?..." fallback must still start from the site root.
        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /** URL of the markdown version of a page URL, in the configured style. */
    public function markdownUrl(string $url, ?string $style = null): string
    {
        $style ??= (string) $this->ctx->setting('link_style', 'query');
        $parts = parse_url($url);
        if ($style === 'suffix' && $parts && empty($parts['query']) && empty($parts['fragment']) && !empty($parts['path']) && !str_ends_with($parts['path'], '/') && !str_contains($url, 'index.php?')) {
            return $url . '.md';
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'format=md';
    }
}

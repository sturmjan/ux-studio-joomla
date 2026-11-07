<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper;

\defined('_JEXEC') or die;

/**
 * "Where did this old URL go?": fuzzy match of an address against the published articles, categories and menu items of
 * the site (slug similarity, optionally the old title). Gives the 404 log a one-click redirect suggestion without
 * scraping the site.
 */
final class Suggester
{
    /** Max rows read per content type (a site with more still gets suggestions from the newest ones). */
    private const MAX_INDEX = 4000;

    /** @var array<int,array{type:string,id:int,title:string,alias:string,catid:int,language:string}>|null */
    private ?array $index = null;

    /**
     * Best-matching current pages for an old address.
     *
     * @return array<int,array{type:string,id:int,title:string,path:string,url:string,route:string,score:int}>
     */
    public function candidates(string $oldUrl, string $title = '', int $limit = 5): array
    {
        $path = Url::parseSource($oldUrl)['path'];
        $slug = self::slugOf($path);
        if ($slug === '' && $title === '') {
            return [];
        }
        $tokens = array_values(array_filter(explode('-', $slug), static fn (string $t): bool => mb_strlen($t) >= 3));
        $words  = str_replace('-', ' ', $slug);
        $title  = mb_strtolower($title, 'UTF-8');

        $scored = [];
        foreach ($this->index() as $item) {
            $alias = $item['alias'];
            $exact = $slug !== '' && $slug === $alias;
            if (!$exact && $tokens) {
                $hit = false;
                foreach ($tokens as $token) {
                    if (str_contains($alias, $token) || str_contains(mb_strtolower($item['title'], 'UTF-8'), $token)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            } elseif (!$exact && $title === '') {
                continue;
            }
            similar_text($slug, $alias, $slugScore);
            $score = $exact ? 100.0 : $slugScore;
            $t     = mb_strtolower($item['title'], 'UTF-8');
            similar_text($words !== '' ? $words : $title, $t, $wordScore);
            $score = max($score, 0.9 * $wordScore);
            if ($title !== '') {
                similar_text($title, $t, $titleScore);
                $score = max($score, 0.5 * $score + 0.5 * $titleScore);
            }
            // An article beats a category or a menu item with the same score: that is what old links mostly meant.
            $scored[] = ['item' => $item, 'score' => $score + ($item['type'] === 'article' ? 0.5 : 0.0)];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach (\array_slice($scored, 0, max(1, min(20, $limit))) as $entry) {
            $item = $entry['item'];
            $route = $this->routeOf($item);
            $url   = $route === '' ? '' : $this->urlOf($route);
            if ($url === '') {
                continue;
            }
            $out[] = [
                'type'  => $item['type'],
                'id'    => $item['id'],
                'title' => $item['title'],
                'path'  => Url::toRelative($url),
                'url'   => $url,
                // Stored as the rule target: re-resolved by the router on every redirect, so it survives alias changes
                // and works with or without URL rewriting.
                'route' => $route,
                'score' => (int) min(100, round($entry['score'])),
            ];
        }

        return $out;
    }

    /** Last meaningful path segment without extension and without a leading numeric id ("12-foo.html" -> "foo"). */
    public static function slugOf(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        $segment  = '';
        while ($segments) {
            $segment = (string) array_pop($segments);
            if (!\in_array($segment, ['index', 'index.php', 'index.html', 'default'], true)) {
                break;
            }
            $segment = '';
        }
        $segment = (string) preg_replace('~\.(html?|php|aspx?|jsp)$~i', '', $segment);
        $segment = (string) preg_replace('~^\d+[-_:]~', '', $segment);
        $segment = trim((string) preg_replace('~[^\p{L}\p{N}]+~u', '-', mb_strtolower($segment, 'UTF-8')), '-');

        return $segment;
    }

    /** @param array{type:string,id:int,title:string,alias:string,catid:int,language:string} $item */
    private function routeOf(array $item): string
    {
        try {
            if ($item['type'] === 'article') {
                return RouteHelper::getArticleRoute($item['id'] . ':' . $item['alias'], $item['catid'], $item['language']);
            }
            if ($item['type'] === 'category') {
                return RouteHelper::getCategoryRoute($item['id'], $item['language']);
            }

            return 'index.php?Itemid=' . $item['id'];
        } catch (\Throwable) {
            return '';
        }
    }

    private function urlOf(string $route): string
    {
        try {
            return Route::link('site', $route, false, Route::TLS_IGNORE, true);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<int,array{type:string,id:int,title:string,alias:string,catid:int,language:string}> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $db    = Repository::db();
        $index = [];

        // Only pages an anonymous visitor really gets: public access level, published and inside the publishing window,
        // in a published public category. Otherwise a one-click rule would end in a 403 or a soft 404.
        $levels = array_map('intval', Access::getAuthorisedViewLevels(0)) ?: [1];
        $now    = Repository::now();
        $rows = $db->setQuery(
            $db->createQuery()->select('a.id, a.title, a.alias, a.catid, a.language')->from($db->quoteName('#__content', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
                ->where('a.state = 1')->where('c.published = 1')
                ->whereIn('a.access', $levels)->whereIn('c.access', $levels)
                ->where('(a.publish_up IS NULL OR a.publish_up <= :n1)')->where('(a.publish_down IS NULL OR a.publish_down > :n2)')
                ->bind(':n1', $now)->bind(':n2', $now)
                ->order('a.id DESC'),
            0,
            self::MAX_INDEX
        )->loadAssocList() ?: [];
        foreach ($rows as $r) {
            $index[] = ['type' => 'article', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'alias' => (string) $r['alias'], 'catid' => (int) $r['catid'], 'language' => (string) $r['language']];
        }

        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'title', 'alias', 'language']))->from($db->quoteName('#__categories'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))->where($db->quoteName('published') . ' = 1')->where($db->quoteName('id') . ' > 1')
                ->whereIn($db->quoteName('access'), $levels),
            0,
            self::MAX_INDEX
        )->loadAssocList() ?: [];
        foreach ($rows as $r) {
            $index[] = ['type' => 'category', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'alias' => (string) $r['alias'], 'catid' => 0, 'language' => (string) $r['language']];
        }

        $rows = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'title', 'alias', 'language']))->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')->where($db->quoteName('client_id') . ' = 0')->where($db->quoteName('id') . ' > 1')
                ->whereIn($db->quoteName('access'), $levels)
                ->where($db->quoteName('type') . ' IN (' . implode(',', array_map([$db, 'quote'], ['component'])) . ')'),
            0,
            self::MAX_INDEX
        )->loadAssocList() ?: [];
        foreach ($rows as $r) {
            $index[] = ['type' => 'menu', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'alias' => (string) $r['alias'], 'catid' => 0, 'language' => (string) $r['language']];
        }

        return $this->index = $index;
    }
}

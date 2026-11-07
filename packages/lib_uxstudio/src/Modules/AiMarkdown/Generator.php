<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Factory;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Builds the documents (article, category, llms.txt, llms-full.txt, ai-sitemap.md) and keeps them in the cache.
 * Every public method returns ['content', 'generated_at', 'hash'] or null when the source is not public.
 * Article text is converted from the stored HTML, content plugins are deliberately NOT run (they could cloak e-mail
 * addresses with JavaScript, load modules or have side effects for an anonymous bot request).
 */
final class Generator
{
    /** Bump to invalidate every cached document after a change of the output format. */
    private const FORMAT = 'f2';

    private const KINDS = ['llms' => 'llms.txt', 'llms-full' => 'llms-full.txt', 'sitemap' => 'ai-sitemap.md'];

    public function __construct(private ModuleContext $ctx, private Content $content, private Cache $cache)
    {
    }

    private function ttl(): int
    {
        return max(0, (int) $this->ctx->setting('cache_ttl', 0));
    }

    private function flag(string $key, bool $default = true): bool
    {
        return filter_var($this->ctx->setting($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /** Hash of every setting that changes the output, part of each cache hash. */
    public function signature(): string
    {
        $keys = ['include_meta_header', 'include_taxonomy', 'include_author', 'link_style', 'site_name', 'site_description', 'extra_text', 'include_menu', 'menu_types',
            'include_categories', 'exclude_categories', 'exclude_ids', 'max_articles', 'full_max_articles', 'enable_markdown', 'enable_llms_full', 'enable_sitemap', 'convert_images'];
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = $this->ctx->setting($k, null);
        }

        // The site root is part of every link. Keying the cache with it stops a forged Host header from planting
        // foreign links in a document that other visitors would then receive.
        return substr(hash('sha256', self::FORMAT . $this->content->root() . ($this->content->rewriteActive() ? 'r' : 'i') . json_encode($data)), 0, 16);
    }

    // ---- Public entry points (cached) -----------------------------------------------------------------------------

    /** @return array{content:string,generated_at:string,hash:string}|null */
    public function article(int $id, bool $force = false): ?array
    {
        $row = $this->content->article($id);
        if (!$row) {
            $this->cache->forget('article:' . $id);

            return null;
        }
        $hash = hash('sha256', implode('|', [$row['id'], $row['title'], $row['alias'], $row['catid'], $row['cat_path'], implode(',', $this->flag('include_taxonomy') ? $this->content->tags((int) $row['id']) : []), $row['introtext'], $row['fulltext'], $row['modified'], $row['publish_up'], $row['metadesc'], $row['author'], $row['cat_title'], $this->signature()]));

        return $this->cached('article:' . $id, 'article', $id, $hash, fn (): string => $this->buildArticle($row, $this->flag('include_meta_header')), $force);
    }

    /** @return array{content:string,generated_at:string,hash:string}|null */
    public function category(int $id, bool $force = false): ?array
    {
        $cat = $this->content->category($id);
        if (!$cat) {
            return null;
        }
        $hash = hash('sha256', $cat['modified_time'] . '|' . $cat['title'] . '|' . $cat['description'] . '|' . $this->content->fingerprint() . '|' . $this->signature());

        return $this->cached('category:' . $id, 'category', $id, $hash, fn (): string => $this->buildCategory($cat), $force);
    }

    /** @return array{content:string,generated_at:string,hash:string} */
    public function aggregate(string $kind, bool $force = false): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown document.');
        }
        $hash    = hash('sha256', $this->content->fingerprint() . '|' . $this->signature() . '|' . $this->siteName() . '|' . $this->siteDescription());
        $builder = match ($kind) {
            'llms'      => fn (): string => $this->buildLlms(),
            'llms-full' => fn (): string => $this->buildLlmsFull(),
            default     => fn (): string => $this->buildSitemap(),
        };

        return $this->cached($kind, $kind, 0, $hash, $builder, $force) ?? ['content' => '', 'generated_at' => '', 'hash' => $hash];
    }

    private function cached(string $key, string $kind, int $ref, string $hash, callable $build, bool $force): ?array
    {
        if (!$force) {
            $hit = $this->cache->get($key, $hash, $this->ttl());
            if ($hit) {
                return ['content' => (string) $hit['content'], 'generated_at' => (string) $hit['generated_at'], 'hash' => $hash];
            }
        }
        if (!$force && $this->ctx->isSite()) {
            // A cache miss costs a rebuild; a public client may not force them at will.
            $wait = (new RateLimiter($this->ctx->db()))->hitClient('ai-markdown:build', 30, 60);
            if ($wait > 0) {
                throw new RateLimited($wait);
            }
        }
        $content = $build();
        if (!$this->content->urlsAvailable() || !$this->content->rootTrusted()) {
            // No known host (command line) or a host that is not the canonical one (forged Host header): usable for
            // this one answer, never stored, so it cannot evict the real documents.
            return ['content' => $content, 'generated_at' => '', 'hash' => $hash];
        }

        return ['content' => $content, 'generated_at' => $this->cache->put($key, $kind, $ref, $hash, $content), 'hash' => $hash];
    }

    /** Rebuilds everything that is public; returns the number of articles. */
    public function regenerateAll(): int
    {
        $this->cache->forget(null, true);
        $rows = $this->content->articles(100000);
        $this->cache->pruneArticles(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $n = 0;
        foreach ($rows as $r) {
            if ($this->article((int) $r['id'], true)) {
                $n++;
            }
        }
        foreach (array_keys(self::KINDS) as $kind) {
            $this->aggregate($kind, true);
        }

        return $n;
    }

    // ---- Site texts -----------------------------------------------------------------------------------------------

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')) ?? '');
    }

    /** Text safe inside a markdown link label. */
    private static function label(string $text): string
    {
        $text = self::oneLine($text);

        return str_replace(['[', ']'], ['(', ')'], $text) ?: '-';
    }

    private static function excerpt(string $html, int $max = 160): string
    {
        // Content plugin tags such as {loadmodule x} or {gallery} are not readable text.
        $t = self::oneLine(preg_replace('/\{[a-z][^{}]*\}/i', ' ', $html) ?? $html);
        if (mb_strlen($t) > $max) {
            $t = rtrim(mb_substr($t, 0, $max - 1)) . '…';
        }

        return self::escape($t);
    }

    /** Neutralises markdown link/code syntax in plain text, so article text cannot form live links in a description. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', '[', ']', '`'], ['\\\\', '\[', '\]', '\`'], $text);
    }

    public function siteName(): string
    {
        $n = self::oneLine((string) $this->ctx->setting('site_name', ''));

        return $n !== '' ? $n : self::oneLine((string) $this->ctx->app()->get('sitename', 'Website'));
    }

    public function siteDescription(): string
    {
        $d = self::oneLine((string) $this->ctx->setting('site_description', ''));

        return $d !== '' ? $d : self::oneLine((string) $this->ctx->app()->get('MetaDesc', ''));
    }

    public function root(): string
    {
        return $this->content->root();
    }

    // ---- Builders -------------------------------------------------------------------------------------------------

    private static function yaml(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if ($value === '' || preg_match('/^[\s\-?:,\[\]{}#&*!|>\'"%@`]|[:#]\s|\s$/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function converter(): HtmlToMarkdown
    {
        return new HtmlToMarkdown($this->content->root());
    }

    /** Stored article HTML to markdown, without the Joomla-specific markers. */
    private function articleBody(array $a): string
    {
        $html = (string) $a['introtext'] . "\n" . (string) $a['fulltext'];
        $html = preg_replace('#<hr\b[^>]*id=["\']system-readmore["\'][^>]*>#i', '', $html) ?? $html;
        $html = preg_replace('#<hr\b[^>]*system-pagebreak[^>]*>#i', '<hr>', $html) ?? $html;
        // Content plugin tags such as {loadmodule ...} are not content.
        $html = preg_replace('/\{(?:loadmodule|loadmoduleid|loadposition|emailcloak|lang)\b[^}]*\}/i', '', $html) ?? $html;
        if (!$this->flag('convert_images')) {
            $html = preg_replace('#<img\b[^>]*>#i', '', $html) ?? $html;
        }

        return $this->converter()->convert($html);
    }

    public function buildArticle(array $a, bool $header): string
    {
        $url   = $this->content->articleUrl($a);
        $title = self::oneLine((string) $a['title']);
        $out   = '';
        if ($header) {
            $date = (string) ($a['publish_up'] ?: $a['created']);
            $out  = "---\n";
            $out .= 'title: ' . self::yaml($title) . "\n";
            $out .= 'url: ' . $url . "\n";
            $out .= "type: article\n";
            $out .= 'date: ' . substr(Factory::getDate($date)->toSql(), 0, 10) . "\n";
            if (!empty($a['modified']) && substr((string) $a['modified'], 0, 10) !== substr($date, 0, 10) && (string) $a['modified'] > '1971') {
                $out .= 'modified: ' . substr((string) $a['modified'], 0, 10) . "\n";
            }
            if ($this->flag('include_author')) {
                $author = trim((string) ($a['created_by_alias'] ?: $a['author']));
                if ($author !== '') {
                    $out .= 'author: ' . self::yaml($author) . "\n";
                }
            }
            if ($this->flag('include_taxonomy')) {
                $out .= 'category: ' . self::yaml(self::oneLine((string) $a['cat_title'])) . "\n";
                $tags = $this->content->tags((int) $a['id']);
                if ($tags) {
                    $out .= 'tags: [' . implode(', ', array_map(fn (string $t): string => self::yaml(self::oneLine($t)), $tags)) . "]\n";
                }
            }
            if (($a['language'] ?? '*') !== '*' && $a['language'] !== '') {
                $out .= 'language: ' . $a['language'] . "\n";
            }
            $desc = self::oneLine((string) $a['metadesc']);
            if ($desc !== '') {
                $out .= 'description: ' . self::yaml($desc) . "\n";
            }
            $out .= "---\n\n";
        }

        return $out . '# ' . $title . "\n\n" . ($header ? '' : 'Source: ' . $url . "\n\n") . $this->articleBody($a) . "\n";
    }

    private function articleDescription(array $a): string
    {
        $d = self::oneLine((string) $a['metadesc']);

        return $d !== '' ? self::excerpt($d) : self::excerpt((string) ($a['introtext'] ?? ''));
    }

    private function buildCategory(array $cat): string
    {
        $url = $this->content->categoryUrl($cat);
        $out = "---\ntitle: " . self::yaml(self::oneLine((string) $cat['title'])) . "\nurl: " . $url . "\ntype: category\n---\n\n# " . self::oneLine((string) $cat['title']) . "\n\n";
        $desc = $this->converter()->convert((string) $cat['description']);
        if ($desc !== '') {
            $out .= $desc . "\n\n";
        }
        $sub = array_filter($this->content->categories(), static fn (array $c): bool => (int) $c['parent_id'] === (int) $cat['id']);
        if ($sub) {
            $out .= "## Subcategories\n\n";
            foreach ($sub as $c) {
                $out .= '- [' . self::label((string) $c['title']) . '](' . $this->content->markdownUrl($this->content->categoryUrl($c)) . ")\n";
            }
            $out .= "\n";
        }
        $articles = $this->content->articles(200, 'date', (int) $cat['id'], true);
        if ($articles) {
            $out .= "## Articles\n\n";
            foreach ($articles as $a) {
                $out .= $this->articleLine($a);
            }
        }

        return rtrim($out) . "\n";
    }

    private function articleLine(array $a, bool $withDate = false): string
    {
        $url  = $this->content->articleUrl($a);
        $line = '- [' . self::label((string) $a['title']) . '](' . $this->mdLink($url) . ')';
        if ($withDate) {
            $line .= ' (' . substr((string) ($a['publish_up'] ?: $a['created']), 0, 10) . ')';
        }
        $desc = isset($a['introtext']) ? $this->articleDescription($a) : self::escape(self::oneLine((string) $a['metadesc']));
        if (!$withDate && $desc !== '') {
            $line .= ': ' . $desc;
        }

        return $line . "\n";
    }

    /** Link to the markdown version when that is switched on, otherwise to the page itself. */
    private function mdLink(string $url): string
    {
        return $this->flag('enable_markdown') ? $this->content->markdownUrl($url) : $url;
    }

    /** Sections of the site: [section title => article rows], newest N articles grouped by category order. */
    private function groupedArticles(int $limit, bool $withText): array
    {
        $rows   = $this->content->articles($limit, 'date', 0, $withText);
        $groups = [];
        usort($rows, static fn (array $x, array $y): int => ((int) $x['cat_lft'] <=> (int) $y['cat_lft']));
        foreach ($rows as $r) {
            $groups[self::label((string) $r['cat_title'])][] = $r;
        }

        return $groups;
    }

    private function head(): string
    {
        $out  = '# ' . $this->siteName() . "\n\n";
        $desc = $this->siteDescription();
        if ($desc !== '') {
            $out .= '> ' . $desc . "\n\n";
        }

        return $out;
    }

    private function buildLlms(): string
    {
        $out   = $this->head();
        $extra = trim((string) $this->ctx->setting('extra_text', ''));
        if ($extra !== '') {
            $out .= str_replace("\r", '', $extra) . "\n\n";
        }

        if ($this->flag('include_menu')) {
            $seen = [];
            $list = '';
            foreach ($this->content->menuItems() as $m) {
                $url = $m['home'] ? $this->content->root() : $this->content->menuUrl($m['id']);
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $list      .= '- [' . self::label($m['title']) . '](' . $url . ")\n";
            }
            if ($list !== '') {
                $out .= "## Pages\n\n" . $list . "\n";
            }
        }

        foreach ($this->groupedArticles(max(1, (int) $this->ctx->setting('max_articles', 200)), true) as $title => $rows) {
            $out .= '## ' . $title . "\n\n";
            foreach ($rows as $a) {
                $out .= $this->articleLine($a);
            }
            $out .= "\n";
        }

        $opt = '';
        if ($this->flag('enable_llms_full')) {
            $opt .= '- [Full content in one file](' . $this->content->virtualUrl('llms-full.txt') . "): all articles as markdown\n";
        }
        if ($this->flag('enable_sitemap')) {
            $opt .= '- [AI sitemap](' . $this->content->virtualUrl('ai-sitemap.md') . "): every published article with its date\n";
        }
        if ($this->flag('include_categories') && $this->flag('enable_markdown')) {
            foreach ($this->content->categories() as $c) {
                $opt .= '- [Category: ' . self::label((string) $c['title']) . '](' . $this->content->markdownUrl($this->content->categoryUrl($c)) . ")\n";
            }
        }
        if ($opt !== '') {
            $out .= "## Optional\n\n" . $opt;
        }

        return rtrim($out) . "\n";
    }

    private function buildLlmsFull(): string
    {
        $out  = rtrim($this->head()) . "\n";
        $rows = $this->content->articles(max(1, (int) $this->ctx->setting('full_max_articles', 100)), 'date', 0, true);
        foreach ($rows as $a) {
            $out .= "\n---\n\n" . $this->buildArticle($a, false);
        }

        return rtrim($out) . "\n";
    }

    private function buildSitemap(): string
    {
        $out = '# AI Sitemap - ' . $this->siteName() . "\n\nGenerated: " . Factory::getDate()->toSql() . "\n\n";
        foreach ($this->groupedArticles(5000, false) as $title => $rows) {
            $out .= '## ' . $title . "\n\n";
            foreach ($rows as $a) {
                $out .= $this->articleLine($a, true);
            }
            $out .= "\n";
        }
        if ($this->flag('include_categories')) {
            $cats = $this->content->categories();
            if ($cats) {
                $out .= "## Categories\n\n";
                foreach ($cats as $c) {
                    $out .= '- [' . self::label((string) $c['title']) . '](' . $this->content->categoryUrl($c) . ")\n";
                }
            }
        }

        return rtrim($out) . "\n";
    }
}

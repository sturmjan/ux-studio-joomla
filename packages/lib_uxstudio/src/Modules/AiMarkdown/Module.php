<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Language\Text;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * AI Markdown (WP: AiMarkdown): llms.txt, llms-full.txt, ai-sitemap.md and a clean markdown version of every public
 * article/category for AI assistants and crawlers, generated locally (no AI service), cached and invalidated on save,
 * with an access log of known AI bots and robots.txt guidance.
 *
 * Pieces: Server (front end, early request handling), Generator + HtmlToMarkdown (documents), Content (what is public),
 * Cache, AccessLog, BotDetector, Robots (robots.txt analysis), Api (admin routes).
 */
final class Module extends BaseModule
{
    private ?Api $api = null;

    public function boot(ModuleContext $ctx): void
    {
        // Content changes make cached documents stale (site and administrator, front-end editing included).
        foreach (['onContentAfterSave', 'onContentAfterDelete', 'onContentChangeState'] as $event) {
            $ctx->on($event, [$this, 'onContentChange']);
        }

        if ($ctx->isSite()) {
            (new Server($ctx, $this->generator(), new Content($ctx), new AccessLog($ctx->db())))->register();
        }
    }

    private function generator(): Generator
    {
        $ctx = $this->ctx();

        return new Generator($ctx, new Content($ctx), new Cache($ctx->db()));
    }

    private function api(): Api
    {
        return $this->api ??= new Api($this->ctx(), $this->generator());
    }

    /** Invalidates what a saved/deleted/re-published article, category, tag or menu item can change. */
    public function onContentChange(Event $event): void
    {
        try {
            $context = (string) $event->getArgument('context', '');
            $cache   = new Cache($this->ctx()->db());
            if (str_starts_with($context, 'com_content.')) {
                $subject = $event->getArgument('subject');
                $ids     = [];
                if (\is_object($subject) && isset($subject->id)) {
                    $ids[] = (int) $subject->id;
                } elseif (\is_array($subject)) {
                    $ids = array_map('intval', $subject);
                }
                // onContentChangeState passes the changed ids as "pks".
                $pks = $event->getArgument('pks');
                if (\is_array($pks)) {
                    $ids = array_merge($ids, array_map('intval', $pks));
                }
                foreach ($ids as $id) {
                    if ($id > 0) {
                        $cache->forget('article:' . $id);
                    }
                }
                $cache->forget(null, true);
                // Optional eager rebuild of a saved article, so the first bot request is already a cache hit.
                if ($event->getName() === 'onContentAfterSave' && $ids && (bool) $this->ctx()->setting('auto_regenerate', false)) {
                    $this->generator()->article($ids[0], true);
                }
            } elseif ($context === 'com_categories.category' || str_starts_with($context, 'com_menus.') || str_starts_with($context, 'com_tags.')) {
                $cache->forget(null, true);
            }
        } catch (\Throwable) {
            // Never block a save because of a cache.
        }
    }

    // ---- Settings -------------------------------------------------------------------------------------------------

    public function settingsSchema(): array
    {
        $p = 'UXS_AI_MARKDOWN_';
        $g = $p . 'SEC_ENDPOINTS';
        $c = $p . 'SEC_CONTENT';
        $k = $p . 'SEC_CACHE';
        $l = $p . 'SEC_LOG';

        return [
            ['key' => 'enable_llms_txt', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_LLMS', 'help' => $p . 'F_LLMS_HELP', 'default' => true],
            ['key' => 'enable_llms_full', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_LLMS_FULL', 'help' => $p . 'F_LLMS_FULL_HELP', 'default' => true],
            ['key' => 'enable_sitemap', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_SITEMAP', 'help' => $p . 'F_SITEMAP_HELP', 'default' => true],
            ['key' => 'enable_markdown', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_MARKDOWN', 'help' => $p . 'F_MARKDOWN_HELP', 'default' => true],
            ['key' => 'enable_accept', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_ACCEPT', 'help' => $p . 'F_ACCEPT_HELP', 'default' => true, 'show_if' => ['key' => 'enable_markdown', 'truthy' => true]],
            ['key' => 'link_style', 'section' => $g, 'type' => 'select', 'label' => $p . 'F_LINK_STYLE', 'help' => $p . 'F_LINK_STYLE_HELP', 'default' => 'query',
                'options' => ['query' => $p . 'LINK_QUERY', 'suffix' => $p . 'LINK_SUFFIX'], 'show_if' => ['key' => 'enable_markdown', 'truthy' => true]],
            ['key' => 'advertise_alternate', 'section' => $g, 'type' => 'toggle', 'label' => $p . 'F_ALTERNATE', 'help' => $p . 'F_ALTERNATE_HELP', 'default' => true, 'show_if' => ['key' => 'enable_markdown', 'truthy' => true]],
            ['key' => 'site_name', 'section' => $g, 'type' => 'text', 'label' => $p . 'F_SITE_NAME', 'help' => $p . 'F_SITE_NAME_HELP', 'default' => '', 'maxlength' => 150],
            ['key' => 'site_description', 'section' => $g, 'type' => 'textarea', 'label' => $p . 'F_SITE_DESC', 'help' => $p . 'F_SITE_DESC_HELP', 'default' => '', 'maxlength' => 500],
            ['key' => 'extra_text', 'section' => $g, 'type' => 'textarea', 'label' => $p . 'F_EXTRA', 'help' => $p . 'F_EXTRA_HELP', 'default' => '', 'maxlength' => 3000],

            ['key' => 'include_menu', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_MENU', 'help' => $p . 'F_MENU_HELP', 'default' => true],
            ['key' => 'menu_types', 'section' => $c, 'type' => 'multiselect', 'label' => $p . 'F_MENU_TYPES', 'help' => $p . 'F_MENU_TYPES_HELP', 'default' => [],
                'options' => (new Content($this->ctx()))->menuTypeOptions(), 'show_if' => ['key' => 'include_menu', 'truthy' => true]],
            ['key' => 'include_categories', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_CATEGORIES', 'help' => $p . 'F_CATEGORIES_HELP', 'default' => true],
            ['key' => 'exclude_categories', 'section' => $c, 'type' => 'text', 'label' => $p . 'F_EXCL_CATS', 'help' => $p . 'F_EXCL_CATS_HELP', 'default' => '', 'maxlength' => 500],
            ['key' => 'exclude_ids', 'section' => $c, 'type' => 'text', 'label' => $p . 'F_EXCL_IDS', 'help' => $p . 'F_EXCL_IDS_HELP', 'default' => '', 'maxlength' => 1000],
            ['key' => 'max_articles', 'section' => $c, 'type' => 'number', 'label' => $p . 'F_MAX_ARTICLES', 'help' => $p . 'F_MAX_ARTICLES_HELP', 'default' => 200, 'min' => 1, 'max' => 5000],
            ['key' => 'full_max_articles', 'section' => $c, 'type' => 'number', 'label' => $p . 'F_FULL_MAX', 'help' => $p . 'F_FULL_MAX_HELP', 'default' => 100, 'min' => 1, 'max' => 2000, 'show_if' => ['key' => 'enable_llms_full', 'truthy' => true]],
            ['key' => 'include_meta_header', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_HEADER', 'help' => $p . 'F_HEADER_HELP', 'default' => true],
            ['key' => 'include_author', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_AUTHOR', 'help' => $p . 'F_AUTHOR_HELP', 'default' => true, 'show_if' => ['key' => 'include_meta_header', 'truthy' => true]],
            ['key' => 'include_taxonomy', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_TAXONOMY', 'help' => $p . 'F_TAXONOMY_HELP', 'default' => true, 'show_if' => ['key' => 'include_meta_header', 'truthy' => true]],
            ['key' => 'convert_images', 'section' => $c, 'type' => 'toggle', 'label' => $p . 'F_IMAGES', 'help' => $p . 'F_IMAGES_HELP', 'default' => true],

            ['key' => 'cache_ttl', 'section' => $k, 'type' => 'select', 'label' => $p . 'F_TTL', 'help' => $p . 'F_TTL_HELP', 'default' => '0', 'options' => [
                '0' => $p . 'TTL_0', '1' => $p . 'TTL_1', '6' => $p . 'TTL_6', '24' => $p . 'TTL_24', '168' => $p . 'TTL_168', '720' => $p . 'TTL_720']],
            ['key' => 'auto_regenerate', 'section' => $k, 'type' => 'toggle', 'label' => $p . 'F_AUTO', 'help' => $p . 'F_AUTO_HELP', 'default' => false],

            ['key' => 'enable_logging', 'section' => $l, 'type' => 'toggle', 'label' => $p . 'F_LOG', 'help' => $p . 'F_LOG_HELP', 'default' => true],
            ['key' => 'log_retention_days', 'section' => $l, 'type' => 'number', 'label' => $p . 'F_RETENTION', 'help' => $p . 'F_RETENTION_HELP', 'default' => 90, 'min' => 0, 'max' => 3650, 'show_if' => ['key' => 'enable_logging', 'truthy' => true]],
        ];
    }

    // ---- Admin API ------------------------------------------------------------------------------------------------

    public function routes(): array
    {
        $a = $this->api();

        // Literal paths before placeholders: the router takes the first pattern that fits.
        return [
            'GET status'           => [$a, 'status'],
            'GET articles'         => [$a, 'articles'],
            'GET preview'          => [$a, 'preview'],
            'POST regenerate-all'  => [$a, 'regenerateAll'],
            'POST regenerate/{id}' => [$a, 'regenerate'],
            'POST cache/clear'     => [$a, 'clearCache'],
            'GET log'              => [$a, 'log'],
            'GET log/stats'        => [$a, 'logStats'],
            'POST log/delete'      => [$a, 'logDelete'],
            'POST log/clear'       => ['handler' => [$a, 'logClear'], 'acl' => 'core.admin'],
            'GET robots'           => [$a, 'robots'],
        ];
    }

    public function migrations(): array
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_ai_markdown_cache` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`cache_key` VARCHAR(64) NOT NULL, '
                . '`kind` VARCHAR(16) NOT NULL DEFAULT \'article\', '
                . '`ref_id` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`content_hash` CHAR(64) NOT NULL DEFAULT \'\', '
                . '`content` MEDIUMTEXT NULL, '
                . '`generated_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_key` (`cache_key`), KEY `idx_kind` (`kind`, `ref_id`)'
                . ') ' . $engine,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_ai_markdown_log` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`created` DATETIME NOT NULL, '
                . '`kind` VARCHAR(16) NOT NULL DEFAULT \'\', '
                . '`ref_id` INT UNSIGNED NOT NULL DEFAULT 0, '
                . '`bot_name` VARCHAR(100) NOT NULL DEFAULT \'\', '
                . '`url` VARCHAR(2048) NOT NULL DEFAULT \'\', '
                . '`via` VARCHAR(10) NOT NULL DEFAULT \'\', '
                . '`ip_address` VARCHAR(45) NOT NULL DEFAULT \'\', '
                . '`user_agent` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_bot` (`bot_name`)'
                . ') ' . $engine,
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'refresh' => ['label' => 'UXS_AI_MARKDOWN_JOB_REFRESH', 'callable' => [$this, 'refresh'], 'interval' => 'P1D'],
        ];
    }

    /** Scheduler job ai-markdown.refresh: drops cached articles that are no longer public and warms llms.txt, llms-full.txt and the sitemap. */
    public function refresh(): string
    {
        $gen     = $this->generator();
        $ctx     = $this->ctx();
        $content = new Content($ctx);
        $cache   = new Cache($ctx->db());
        $rows    = $content->articles(100000);
        $cache->pruneArticles(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        if (!$content->urlsAvailable()) {
            // Started from the command line without a configured live_site: the host is unknown, so nothing is built
            // (links would be wrong). The documents are rebuilt by the next web request.
            $cache->forget(null, true);

            return 'ok, ' . \count($rows) . ' public articles; documents rebuild on the next web request (no live_site in CLI)';
        }
        foreach (['llms', 'llms-full', 'sitemap'] as $kind) {
            $gen->aggregate($kind);
        }

        return 'ok, ' . \count($rows) . ' public articles';
    }

    public function retention(): array
    {
        return [[
            'table'  => '#__uxstudio_ai_markdown_log',
            'column' => 'created',
            'days'   => fn (): int => (int) $this->ctx()->setting('log_retention_days', 90),
        ]];
    }

    public function onEnable(): void
    {
        try {
            (new Content($this->ctx()))->rememberRoot();
            (new Cache($this->ctx()->db()))->forget();
        } catch (\Throwable) {
            // Table is created by the migration that runs right before; ignore a first-run race.
        }
    }

    public function dashboardWidget(): ?array
    {
        try {
            $stats = (new AccessLog($this->ctx()->db()))->stats();
        } catch (\Throwable) {
            return null;
        }

        return [
            'title'  => Text::_('UXS_AI_MARKDOWN_NAME'),
            'value'  => $stats['last_24h'],
            'text'   => Text::sprintf('UXS_AI_MARKDOWN_WIDGET', $stats['total']),
            'status' => 'ok',
        ];
    }

    /**
     * UX1 Network app feed: route "ai-markdown" => endpoints and AI bot access summary (see docs/modules/ai-markdown.md).
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function caFeed(string $route, array $query, array $body): array
    {
        if ($route !== 'ai-markdown') {
            throw new ApiException('Unknown route.', 404, 'not_found');
        }

        return $this->api()->feed();
    }
}

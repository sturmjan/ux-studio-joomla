<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Redirects: SEO-safe 301/302/307/308/410 rules with chain and loop protection, regular expressions with ReDoS limits,
 * an aggregated 404 log with one-click redirect suggestions, CSV import/export and hit counters.
 *
 * Pieces: Engine (front-end matching, onAfterRoute + onError), Repository (rule store), NotFoundLog (aggregated
 * 404s), Suggester (where did the old URL go), Tester (URL test + audit), Importer (CSV, Joomla com_redirect), Api.
 */
final class Module extends BaseModule
{
    /** @var array<int,array{url:string,state:int}> article state before a save, by article id */
    private static array $before = [];

    public function boot(ModuleContext $ctx): void
    {
        (new Engine($ctx))->register();

        if ((bool) $ctx->setting('monitor_slugs', true)) {
            $ctx->on('onContentBeforeSave', [$this, 'onBeforeSave']);
            $ctx->on('onContentAfterSave', [$this, 'onAfterSave']);
        }
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'monitor_slugs', 'type' => 'toggle', 'label' => 'UXS_REDIRECTS_S_MONITOR', 'help' => 'UXS_REDIRECTS_S_MONITOR_HELP', 'default' => true],
            ['key' => 'pass_query', 'type' => 'toggle', 'label' => 'UXS_REDIRECTS_S_PASS_QUERY', 'help' => 'UXS_REDIRECTS_S_PASS_QUERY_HELP', 'default' => true],
            ['key' => 'permanent_cache_ttl', 'type' => 'number', 'label' => 'UXS_REDIRECTS_S_CACHE_TTL', 'help' => 'UXS_REDIRECTS_S_CACHE_TTL_HELP', 'default' => 3600, 'min' => 0, 'max' => 31536000],
            ['key' => 'track_hits', 'type' => 'toggle', 'label' => 'UXS_REDIRECTS_S_TRACK_HITS', 'help' => 'UXS_REDIRECTS_S_TRACK_HITS_HELP', 'default' => true],
            ['key' => 'log_404', 'type' => 'toggle', 'label' => 'UXS_REDIRECTS_S_LOG_404', 'help' => 'UXS_REDIRECTS_S_LOG_404_HELP', 'default' => true],
            ['key' => 'log_404_retention_days', 'type' => 'number', 'label' => 'UXS_REDIRECTS_S_RETENTION', 'help' => 'UXS_REDIRECTS_S_RETENTION_HELP', 'default' => 90, 'min' => 0, 'max' => 3650, 'show_if' => ['key' => 'log_404', 'truthy' => true]],
        ];
    }

    public function routes(): array
    {
        $api = new Api();

        // Every write needs core.admin: rules redirect the whole site (regex, external targets), the equivalent of WP manage_options.
        // Literal paths first: the router takes the first pattern that fits ('rules/{id}' would swallow 'rules/delete').
        return [
            'GET status'              => [$api, 'status'],
            'GET rules'               => [$api, 'listRules'],
            'POST rules'              => ['handler' => [$api, 'upsert'], 'acl' => 'core.admin'],
            'POST rules/delete'       => ['handler' => [$api, 'deleteRules'], 'acl' => 'core.admin'],
            'POST rules/reset-hits'   => ['handler' => [$api, 'resetHits'], 'acl' => 'core.admin'],
            'POST rules/{id}'         => ['handler' => [$api, 'updateOne'], 'acl' => 'core.admin'],
            'POST test'               => [$api, 'test'],
            'POST audit'              => [$api, 'audit'],
            'GET 404'                 => [$api, 'list404'],
            'POST 404/delete'         => ['handler' => [$api, 'delete404'], 'acl' => 'core.admin'],
            'GET suggest'             => [$api, 'suggest'],
            'POST import/csv'         => ['handler' => [$api, 'importCsv'], 'acl' => 'core.admin'],
            'POST import/joomla'      => ['handler' => [$api, 'importJoomla'], 'acl' => 'core.admin'],
            'GET export'              => [$api, 'export'],
        ];
    }

    public function migrations(): array
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_redirects` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`source` VARCHAR(2000) NOT NULL DEFAULT \'\', '
                . '`source_key` VARCHAR(2000) NOT NULL DEFAULT \'\', '
                . '`source_hash` CHAR(40) NOT NULL, '
                . '`source_cs` VARCHAR(2000) NOT NULL DEFAULT \'\', '
                . '`case_sensitive` TINYINT(1) NOT NULL DEFAULT 0, '
                . '`query_mode` VARCHAR(10) NOT NULL DEFAULT \'\', '
                . '`match_type` VARCHAR(10) NOT NULL DEFAULT \'exact\', '
                . '`target` VARCHAR(2000) NOT NULL DEFAULT \'\', '
                . '`target_hash` CHAR(40) NOT NULL DEFAULT \'\', '
                . '`code` SMALLINT UNSIGNED NOT NULL DEFAULT 301, '
                . '`enabled` TINYINT(1) NOT NULL DEFAULT 1, '
                . '`hits` BIGINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`last_hit` DATETIME NULL, '
                . '`origin` VARCHAR(20) NOT NULL DEFAULT \'manual\', '
                . '`note` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`position` INT NOT NULL DEFAULT 0, '
                . '`created_at` DATETIME NOT NULL, '
                . '`updated_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_source` (`source_hash`), KEY `idx_target` (`target_hash`), KEY `idx_match` (`match_type`, `enabled`)'
                . ') ' . $engine,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_redirects_404` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`url_hash` CHAR(40) NOT NULL, '
                . '`url` VARCHAR(2000) NOT NULL DEFAULT \'\', '
                . '`hits` BIGINT UNSIGNED NOT NULL DEFAULT 1, '
                . '`first_seen` DATETIME NOT NULL, '
                . '`last_seen` DATETIME NOT NULL, '
                . '`referrer` VARCHAR(500) NOT NULL DEFAULT \'\', '
                . '`user_agent` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_url` (`url_hash`), KEY `idx_last_seen` (`last_seen`), KEY `idx_hits` (`hits`)'
                . ') ' . $engine,
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'cleanup' => ['label' => 'UXS_REDIRECTS_JOB_CLEANUP', 'callable' => [$this, 'cleanup'], 'interval' => 'P1D'],
        ];
    }

    /** Scheduler job redirects.cleanup: keeps the 404 log under its row cap (age retention is done by the core). */
    public function cleanup(): string
    {
        NotFoundLog::enforceCap();

        return 'ok';
    }

    public function retention(): array
    {
        return [[
            'table'  => '#__uxstudio_redirects_404',
            'column' => 'last_seen',
            'days'   => fn (): int => (int) $this->ctx()->setting('log_404_retention_days', 90),
        ]];
    }

    public function dashboardWidget(): ?array
    {
        $db = Repository::db();
        try {
            $rules = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(Repository::TABLE)))->loadResult();
            $nf    = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName(NotFoundLog::TABLE)))->loadResult();
        } catch (\Throwable) {
            return null;
        }

        return [
            'title'  => Text::_('UXS_REDIRECTS_NAME'),
            'value'  => $rules,
            'text'   => Text::sprintf('UXS_REDIRECTS_WIDGET', $nf),
            'status' => 'ok',
        ];
    }

    /*
     * ------------------------------------------------------------------
     * Slug monitor: a published article that changes its URL gets a 301 from the old address
     * ------------------------------------------------------------------
     */

    public function onBeforeSave(Event $event): void
    {
        try {
            if (!$this->isArticle($event)) {
                return;
            }
            $item = $event->getArgument('subject');
            $id   = (int) ($item->id ?? 0);
            if ($id < 1) {
                return;
            }
            // The table is bound to the NEW values already; the database still holds the old ones.
            $db  = $this->ctx()->db();
            $old = $db->setQuery(
                $db->createQuery()->select($db->quoteName(['id', 'alias', 'catid', 'state', 'language']))->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
            )->loadAssoc();
            if (!$old) {
                return;
            }
            self::$before[$id] = ['url' => $this->articleUrl($old), 'state' => (int) $old['state']];
        } catch (\Throwable) {
            // Monitoring must never block saving an article.
        }
    }

    public function onAfterSave(Event $event): void
    {
        try {
            if (!$this->isArticle($event)) {
                return;
            }
            $item = $event->getArgument('subject');
            $id   = (int) ($item->id ?? 0);
            $was  = self::$before[$id] ?? null;
            unset(self::$before[$id]);
            if ($was === null || $was['url'] === '' || $was['state'] !== 1 || (int) ($item->state ?? 0) !== 1) {
                return;
            }
            $new = $this->articleUrl(['id' => $id, 'alias' => (string) $item->alias, 'catid' => (int) $item->catid, 'language' => (string) ($item->language ?? '*')]);
            if ($new === '') {
                return;
            }
            $oldKey = Url::targetKey($was['url']);
            $newKey = Url::targetKey($new);
            if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
                return;
            }

            // Content lives at the new URL now: a rule there would hide it (e.g. the alias was changed back).
            Repository::deleteBySourceKeys([$newKey, explode('?', $newKey, 2)[0]]);
            Repository::save([
                'source' => Url::toRelative($was['url']),
                'target' => Url::toRelative($new),
                'code'   => 301,
                'origin' => 'auto',
                'note'   => Text::sprintf('UXS_REDIRECTS_AUTO_NOTE', Url::toRelative($new)),
            ], 'update');
        } catch (\Throwable) {
            // See onBeforeSave.
        }
    }

    private function isArticle(Event $event): bool
    {
        $context = (string) $event->getArgument('context', '');

        return $context === 'com_content.article' || $context === 'com_content.form';
    }

    /** @param array{id:mixed,alias:mixed,catid:mixed,language:mixed} $a */
    private function articleUrl(array $a): string
    {
        $route = RouteHelper::getArticleRoute((int) $a['id'] . ':' . (string) $a['alias'], (int) $a['catid'], (string) $a['language']);

        return Route::link('site', $route, false, Route::TLS_IGNORE, true);
    }
}

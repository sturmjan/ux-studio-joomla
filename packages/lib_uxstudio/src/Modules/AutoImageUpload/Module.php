<?php

namespace Ux1\UxStudio\Modules\AutoImageUpload;

use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Auto image upload: when an article (or category / custom module) is saved, images that link to other websites are
 * downloaded into images/<folder>/ and the addresses in the text are rewritten. The same job can be run over the
 * existing articles from the admin screen.
 */
final class Module extends BaseModule
{
    /** Hard cap of the time a single save may spend downloading. */
    private const SAVE_SECONDS = 20.0;

    public function boot(ModuleContext $ctx): void
    {
        $ctx->on('onContentBeforeSave', [$this, 'onBeforeSave']);
        // Custom HTML modules are stored by com_modules, which raises the extension event instead of the content one.
        $ctx->on('onExtensionBeforeSave', [$this, 'onBeforeSave']);
    }

    public function settingsSchema(): array
    {
        $p = 'UXS_AUTO_IMAGE_UPLOAD_';

        return [
            ['key' => 'contexts', 'type' => 'multiselect', 'label' => $p . 'CONTEXTS', 'help' => $p . 'CONTEXTS_HELP', 'default' => ['articles'],
                'options' => ['articles' => $p . 'CTX_ARTICLES', 'categories' => $p . 'CTX_CATEGORIES', 'modules' => $p . 'CTX_MODULES'], 'section' => $p . 'SECTION_SOURCES'],
            ['key' => 'include_images', 'type' => 'toggle', 'label' => $p . 'INCLUDE_IMAGES', 'help' => $p . 'INCLUDE_IMAGES_HELP', 'default' => true, 'section' => $p . 'SECTION_SOURCES'],
            ['key' => 'process_data_uri', 'type' => 'toggle', 'label' => $p . 'DATA_URI', 'help' => $p . 'DATA_URI_HELP', 'default' => true, 'section' => $p . 'SECTION_SOURCES'],
            ['key' => 'excluded_domains', 'type' => 'textarea', 'label' => $p . 'EXCLUDED', 'help' => $p . 'EXCLUDED_HELP', 'default' => '', 'maxlength' => 3000, 'section' => $p . 'SECTION_SOURCES'],
            ['key' => 'max_per_save', 'type' => 'number', 'label' => $p . 'MAX_PER_SAVE', 'help' => $p . 'MAX_PER_SAVE_HELP', 'default' => 10, 'min' => 1, 'max' => 50, 'section' => $p . 'SECTION_SOURCES'],
            ['key' => 'max_file_size_mb', 'type' => 'number', 'label' => $p . 'MAX_SIZE', 'help' => $p . 'MAX_SIZE_HELP', 'default' => 5, 'min' => 1, 'max' => 50, 'section' => $p . 'SECTION_SOURCES'],

            ['key' => 'folder', 'type' => 'text', 'label' => $p . 'FOLDER', 'help' => $p . 'FOLDER_HELP', 'default' => 'external/%year%/%month%', 'maxlength' => 120, 'section' => $p . 'SECTION_FILES'],
            ['key' => 'file_pattern', 'type' => 'text', 'label' => $p . 'FILE_PATTERN', 'help' => $p . 'FILE_PATTERN_HELP', 'default' => '%filename%', 'maxlength' => 120, 'section' => $p . 'SECTION_FILES'],
            ['key' => 'alt_pattern', 'type' => 'text', 'label' => $p . 'ALT_PATTERN', 'help' => $p . 'ALT_PATTERN_HELP', 'default' => '%image_alt%', 'maxlength' => 190, 'section' => $p . 'SECTION_FILES'],
            ['key' => 'base_url', 'type' => 'text', 'label' => $p . 'BASE_URL', 'help' => $p . 'BASE_URL_HELP', 'default' => '', 'maxlength' => 190, 'placeholder' => 'https://cdn.example.com', 'section' => $p . 'SECTION_FILES'],
        ];
    }

    public function routes(): array
    {
        return [
            'GET overview'  => [$this, 'overview'],
            'GET log'       => [$this, 'log'],
            // Rewrites the text of existing articles in bulk: super user level only.
            'POST bulk/run' => ['handler' => [$this, 'bulkRun'], 'acl' => 'core.admin'],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_auto_image_upload` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `url_hash` CHAR(40) NOT NULL, `url` VARCHAR(1000) NOT NULL DEFAULT \'\', '
                . '`status` VARCHAR(8) NOT NULL DEFAULT \'\', `path` VARCHAR(500) NOT NULL DEFAULT \'\', `error` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`context` VARCHAR(60) NOT NULL DEFAULT \'\', `item_id` INT UNSIGNED NOT NULL DEFAULT 0, `created` DATETIME NOT NULL, `updated` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_url` (`url_hash`), KEY `idx_status` (`status`), KEY `idx_updated` (`updated`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_auto_image_upload', 'column' => 'updated', 'days' => 180]];
    }

    public function dashboardWidget(): ?array
    {
        $c = (new Store($this->ctx()->db()))->counts();

        return ['title' => $this->ctx()->t('UXS_AUTO_IMAGE_UPLOAD_NAME'), 'value' => $c['ok'], 'text' => $this->ctx()->t('UXS_AUTO_IMAGE_UPLOAD_WIDGET_TEXT'), 'status' => $c['failed'] > 0 ? 'warn' : 'ok'];
    }

    // ------------------------------------------------------------------ save hook

    /** onContentBeforeSave: rewrite the text of the item that is about to be stored. */
    public function onBeforeSave(Event $event): void
    {
        try {
            $args    = array_values($event->getArguments());
            $context = (string) ($args[0] ?? '');
            $item    = $args[1] ?? null;
            if (!\is_object($item)) {
                return;
            }
            $kind = $this->kindOf($context, $item);
            if ($kind === null || !\in_array($kind, (array) $this->ctx()->setting('contexts', ['articles']), true)) {
                return;
            }

            $budget    = max(1, (int) $this->ctx()->setting('max_per_save', 10));
            $processor = $this->processor();
            $deadline  = $this->saveDeadline();
            $fields    = $this->fieldsOf($kind, $item);
            $info      = ['context' => $context, 'id' => (int) ($item->id ?? 0), 'title' => (string) ($item->title ?? $item->name ?? ''), 'alias' => (string) ($item->alias ?? '')];
            $changed   = $this->processFields($processor, $kind, $fields, $info, $budget, false, $deadline);
            foreach ($changed['fields'] as $name => $value) {
                $item->$name = $value;
            }
        } catch (\Throwable $e) {
            // The save must never fail because of this module.
            try {
                \Joomla\CMS\Log\Log::add('auto-image-upload: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Moment after which a save downloads nothing more. Based on SAVE_SECONDS, but never beyond what the PHP time limit
     * leaves (the limit is raised when the host allows it; a download is also cut to the time left, see Processor).
     */
    private function saveDeadline(): float
    {
        @set_time_limit((int) self::SAVE_SECONDS + 30);
        $seconds = self::SAVE_SECONDS;
        $max     = (int) \ini_get('max_execution_time');
        if ($max > 0) {
            $seconds = min($seconds, max(3.0, $max * 0.5));
        }

        return microtime(true) + $seconds;
    }

    /** @return string|null articles | categories | modules */
    private function kindOf(string $context, object $item): ?string
    {
        if ($context === 'com_content.article' || $context === 'com_content.form') {
            return 'articles';
        }
        if (str_starts_with($context, 'com_categories.category')) {
            return 'categories';
        }
        if ($context === 'com_modules.module' && ($item->module ?? '') === 'mod_custom') {
            return 'modules';
        }

        return null;
    }

    /** @return array<string,string> field name => current value */
    private function fieldsOf(string $kind, object $item): array
    {
        return match ($kind) {
            'articles'   => ['introtext' => (string) ($item->introtext ?? ''), 'fulltext' => (string) ($item->fulltext ?? ''), 'images' => (string) ($item->images ?? '')],
            'categories' => ['description' => (string) ($item->description ?? '')],
            default      => ['content' => (string) ($item->content ?? '')],
        };
    }

    /**
     * @param array<string,string>                                   $fields
     * @param array{context:string,id:int,title:string,alias:string} $info
     *
     * @return array{fields:array<string,string>,found:int,replaced:int,failed:int,left:int} fields = only the changed ones
     */
    private function processFields(Processor $processor, string $kind, array $fields, array $info, int &$budget, bool $dryRun, float $deadline): array
    {
        $out = ['fields' => [], 'found' => 0, 'replaced' => 0, 'failed' => 0, 'left' => 0];
        foreach ($fields as $name => $value) {
            if ($name === 'images') {
                if (!$this->ctx()->setting('include_images', true) || $value === '') {
                    continue;
                }
                $data = json_decode($value, true);
                if (!\is_array($data)) {
                    continue;
                }
                $touched = false;
                foreach (['image_intro', 'image_fulltext'] as $key) {
                    $src = (string) ($data[$key] ?? '');
                    if ($src === '' || !$processor->isCandidate($src)) {
                        continue;
                    }
                    $out['found']++;
                    if ($dryRun) {
                        continue;
                    }
                    $alt = (string) ($data[$key . '_alt'] ?? '');
                    $res = $processor->resolve($src, $info, $alt, $budget, $deadline);
                    if ($res['state'] === 'ok') {
                        $data[$key] = Processor::withSize($res['path']);
                        $out['replaced']++;
                        $touched = true;
                    } else {
                        $out[$res['state']]++;
                    }
                }
                if ($touched) {
                    $out['fields'][$name] = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                continue;
            }
            $r = $processor->process($value, $info, $budget, $dryRun, $deadline);
            foreach (['found', 'replaced', 'failed', 'left'] as $k) {
                $out[$k] += $r[$k];
            }
            if ($r['html'] !== $value) {
                $out['fields'][$name] = $r['html'];
            }
        }

        return $out;
    }

    private function processor(): Processor
    {
        return new Processor($this->ctx()->settings(), new Store($this->ctx()->db()), $this->ctx()->http());
    }

    // ------------------------------------------------------------------ API

    /** GET auto-image-upload/overview */
    public function overview(ApiRequest $r): array
    {
        $registry = \Ux1\UxStudio\Core\Kernel::instance()->registry();

        return [
            'counts'          => (new Store($this->ctx()->db()))->counts(),
            'contexts'        => (array) $this->ctx()->setting('contexts', ['articles']),
            'optimizer'       => $registry->has('image-optimizer') && $registry->isEnabled('image-optimizer'),
            'curl'            => \function_exists('curl_init'),
            'max_per_save'    => (int) $this->ctx()->setting('max_per_save', 10),
        ];
    }

    /** GET auto-image-upload/log?page=&status= */
    public function log(ApiRequest $r): array
    {
        $page = max(1, $r->int('page', 1));
        $per  = 25;
        $res  = (new Store($this->ctx()->db()))->page($page, $per, $r->string('status'));

        return $res + ['page' => $page, 'per' => $per];
    }

    /**
     * POST auto-image-upload/bulk/run {after_id, dry_run}
     * Handles a few articles per call; the screen calls it again with next_after_id until done is true.
     */
    public function bulkRun(ApiRequest $r): array
    {
        @set_time_limit(90);
        $after  = max(0, $r->int('after_id', 0));
        $dryRun = $r->bool('dry_run');
        $budget = $dryRun ? 0 : 8; // downloads in this call
        $deadline = microtime(true) + 20.0;

        $db = $this->ctx()->db();
        $q  = $db->createQuery()
            ->select($db->quoteName(['id', 'title', 'alias', 'introtext', 'fulltext', 'images', 'checked_out']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' > :after')->bind(':after', $after, ParameterType::INTEGER)
            ->where('(' . $db->quoteName('introtext') . ' LIKE ' . $db->quote('%<img%') . ' OR ' . $db->quoteName('fulltext') . ' LIKE ' . $db->quote('%<img%') . ' OR ' . $db->quoteName('images') . ' LIKE ' . $db->quote('%http%') . ')')
            ->order($db->quoteName('id') . ' ASC');
        $rows = $db->setQuery($q, 0, 5)->loadAssocList() ?: [];

        $processor = $this->processor();
        $res       = ['scanned' => 0, 'articles' => 0, 'found' => 0, 'replaced' => 0, 'failed' => 0, 'locked' => 0, 'next_after_id' => $after, 'done' => false, 'errors' => []];
        foreach ($rows as $row) {
            if (!$dryRun && ($budget <= 0 || microtime(true) > $deadline)) {
                break; // out of budget: the next call starts at this article
            }
            $lockedBy = (int) $row['checked_out'];
            if ($lockedBy > 0 && $lockedBy !== (int) $r->user()->id) {
                // Somebody has the article open in the editor: saving it would overwrite our change (or ours theirs).
                $res['locked']++;
                $res['next_after_id'] = (int) $row['id'];
                continue;
            }
            $info    = ['context' => 'bulk', 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'alias' => (string) $row['alias']];
            $fields  = ['introtext' => (string) $row['introtext'], 'fulltext' => (string) $row['fulltext'], 'images' => (string) $row['images']];
            $r2      = $this->processFields($processor, 'articles', $fields, $info, $budget, $dryRun, $deadline);
            $res['scanned']++;
            $res['found']    += $r2['found'];
            $res['replaced'] += $r2['replaced'];
            $res['failed']   += $r2['failed'];
            if ($r2['found'] > 0) {
                $res['articles']++;
            }
            if ($r2['fields'] && !$dryRun) {
                $u = $db->createQuery()->update($db->quoteName('#__content'));
                $bind = [];
                foreach ($r2['fields'] as $name => $value) {
                    $bind[$name] = $value;
                    $u->set($db->quoteName($name) . ' = :f_' . $name)->bind(':f_' . $name, $bind[$name]);
                }
                $rowId = (int) $row['id'];
                $u->where($db->quoteName('id') . ' = :id')->bind(':id', $rowId, ParameterType::INTEGER);
                $db->setQuery($u)->execute();
            }
            if ($r2['left'] > 0) {
                break; // the article still has images to download: stay on it
            }
            $res['next_after_id'] = (int) $row['id'];
        }
        // A short page means the end of the table (unless we stopped early because of the budget).
        $res['done']   = \count($rows) < 5 && $res['next_after_id'] === (int) (end($rows)['id'] ?? $after);
        $res['errors'] = $processor->errors();
        if ($res['replaced'] > 0) {
            try {
                \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => 'com_content'])->clean();
            } catch (\Throwable) {
            }
        }

        return $res;
    }
}

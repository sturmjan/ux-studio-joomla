<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

use Joomla\Event\Event;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Image optimizer: compresses JPEG / PNG images when they are saved through the Media Manager (the same events the
 * core "Media Action" plugins use), creates WebP / AVIF siblings, optimizes the existing images folder in batches
 * (admin screen or scheduler task) and keeps the originals so a result can be reverted.
 */
final class Module extends BaseModule
{
    /** @var array<int,array{original:string,res:array<string,mixed>}> results computed before the Media Manager saved a file */
    private array $pending = [];

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->setting('auto_optimize', true)) {
            $ctx->on('onContentBeforeSave', [$this, 'onBeforeSave']);
            $ctx->on('onContentAfterSave', [$this, 'onAfterSave']);
        }
        // Siblings and the backup of a deleted file go with it.
        $ctx->on('onContentAfterDelete', [$this, 'onAfterDelete']);

        if ($ctx->isAdmin()) {
            $this->syncDelivery();
        }
    }

    public function onEnable(): void
    {
        $this->syncDelivery();
    }

    public function onDisable(): void
    {
        Delivery::remove();
    }

    public function settingsSchema(): array
    {
        $p = 'UXS_IMAGE_OPTIMIZER_';

        return [
            ['key' => 'jpeg_quality', 'type' => 'number', 'label' => $p . 'JPEG_QUALITY', 'help' => $p . 'JPEG_QUALITY_HELP', 'default' => 82, 'min' => 40, 'max' => 100, 'section' => $p . 'SECTION_COMPRESSION'],
            ['key' => 'max_width', 'type' => 'number', 'label' => $p . 'MAX_WIDTH', 'help' => $p . 'MAX_WIDTH_HELP', 'default' => 2560, 'min' => 0, 'max' => 20000, 'section' => $p . 'SECTION_COMPRESSION'],
            ['key' => 'max_height', 'type' => 'number', 'label' => $p . 'MAX_HEIGHT', 'help' => $p . 'MAX_HEIGHT_HELP', 'default' => 0, 'min' => 0, 'max' => 20000, 'section' => $p . 'SECTION_COMPRESSION'],
            ['key' => 'min_size_kb', 'type' => 'number', 'label' => $p . 'MIN_SIZE', 'help' => $p . 'MIN_SIZE_HELP', 'default' => 10, 'min' => 0, 'max' => 10240, 'section' => $p . 'SECTION_COMPRESSION'],
            ['key' => 'keep_backup', 'type' => 'toggle', 'label' => $p . 'KEEP_BACKUP', 'help' => $p . 'KEEP_BACKUP_HELP', 'default' => true, 'section' => $p . 'SECTION_COMPRESSION'],
            ['key' => 'library', 'type' => 'select', 'label' => $p . 'LIBRARY', 'help' => $p . 'LIBRARY_HELP', 'default' => 'auto',
                'options' => ['auto' => $p . 'LIBRARY_AUTO', 'gd' => 'GD', 'imagick' => 'Imagick'], 'section' => $p . 'SECTION_COMPRESSION'],

            ['key' => 'convert_webp', 'type' => 'toggle', 'label' => $p . 'CONVERT_WEBP', 'help' => $p . 'CONVERT_WEBP_HELP', 'default' => true, 'section' => $p . 'SECTION_FORMATS'],
            ['key' => 'webp_quality', 'type' => 'number', 'label' => $p . 'WEBP_QUALITY', 'default' => 80, 'min' => 1, 'max' => 100, 'section' => $p . 'SECTION_FORMATS', 'show_if' => ['key' => 'convert_webp', 'truthy' => true]],
            ['key' => 'convert_avif', 'type' => 'toggle', 'label' => $p . 'CONVERT_AVIF', 'help' => $p . 'CONVERT_AVIF_HELP', 'default' => false, 'section' => $p . 'SECTION_FORMATS'],
            ['key' => 'avif_quality', 'type' => 'number', 'label' => $p . 'AVIF_QUALITY', 'help' => $p . 'AVIF_QUALITY_HELP', 'default' => 55, 'min' => 1, 'max' => 100, 'section' => $p . 'SECTION_FORMATS', 'show_if' => ['key' => 'convert_avif', 'truthy' => true]],
            ['key' => 'htaccess_delivery', 'type' => 'toggle', 'label' => $p . 'DELIVERY', 'help' => $p . 'DELIVERY_HELP', 'default' => false, 'section' => $p . 'SECTION_FORMATS'],

            ['key' => 'auto_optimize', 'type' => 'toggle', 'label' => $p . 'AUTO', 'help' => $p . 'AUTO_HELP', 'default' => true, 'section' => $p . 'SECTION_AUTOMATION'],
            ['key' => 'bulk_enabled', 'type' => 'toggle', 'label' => $p . 'BULK', 'help' => $p . 'BULK_HELP', 'default' => false, 'section' => $p . 'SECTION_AUTOMATION'],
            ['key' => 'bulk_batch', 'type' => 'number', 'label' => $p . 'BULK_BATCH', 'help' => $p . 'BULK_BATCH_HELP', 'default' => 10, 'min' => 1, 'max' => 50, 'section' => $p . 'SECTION_AUTOMATION', 'show_if' => ['key' => 'bulk_enabled', 'truthy' => true]],
            ['key' => 'exclude_paths', 'type' => 'textarea', 'label' => $p . 'EXCLUDE', 'help' => $p . 'EXCLUDE_HELP', 'default' => '', 'section' => $p . 'SECTION_AUTOMATION', 'maxlength' => 2000],
        ];
    }

    public function routes(): array
    {
        $api = new Api($this);

        return [
            'GET overview'  => [$api, 'overview'],
            'GET scan'      => [$api, 'scan'],
            'GET items'     => [$api, 'items'],
            // Destructive: they rewrite or replace files of the images folder, so they need a super user level right.
            'POST bulk/run' => ['handler' => [$api, 'bulkRun'], 'acl' => 'core.admin'],
            'POST optimize' => ['handler' => [$api, 'optimize'], 'acl' => 'core.admin'],
            'POST restore'  => ['handler' => [$api, 'restore'], 'acl' => 'core.admin'],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_image_optimizer` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `path_hash` CHAR(40) NOT NULL, `path` VARCHAR(700) NOT NULL, '
                . '`status` VARCHAR(12) NOT NULL DEFAULT \'\', `original_size` BIGINT UNSIGNED NOT NULL DEFAULT 0, `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`webp_size` BIGINT UNSIGNED NOT NULL DEFAULT 0, `avif_size` BIGINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`width` INT UNSIGNED NOT NULL DEFAULT 0, `height` INT UNSIGNED NOT NULL DEFAULT 0, `backup` TINYINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`source` VARCHAR(12) NOT NULL DEFAULT \'\', `note` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`created` DATETIME NOT NULL, `updated` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_path` (`path_hash`), KEY `idx_status` (`status`), KEY `idx_updated` (`updated`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'bulk' => ['label' => 'UXS_IMAGE_OPTIMIZER_JOB_BULK', 'callable' => [$this, 'scheduledBulk'], 'interval' => 'PT15M'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        $totals = $this->store()->totals();

        return [
            'title'  => $this->ctx()->t('UXS_IMAGE_OPTIMIZER_NAME'),
            'value'  => self::bytes($totals['saved']),
            'text'   => $this->ctx()->t('UXS_IMAGE_OPTIMIZER_WIDGET_TEXT', $totals['optimized']),
            'status' => 'ok',
        ];
    }

    // ------------------------------------------------------------------ scheduler

    /** Scheduler job image-optimizer.bulk: one batch of the images folder (only when the bulk setting is on). */
    public function scheduledBulk(): string
    {
        if (!$this->ctx()->setting('bulk_enabled', false)) {
            return 'skipped: background optimization is off';
        }
        $r = $this->runBatch((int) $this->ctx()->setting('bulk_batch', 10), 25.0);

        return sprintf('processed: %d, optimized: %d, saved: %s%s', $r['processed'], $r['optimized'], self::bytes($r['saved']), $r['done'] ? ', all done' : '');
    }

    // ------------------------------------------------------------------ Media Manager events

    /** onContentBeforeSave (com_media.file): compress the bytes before the adapter writes them. */
    public function onBeforeSave(Event $event): void
    {
        try {
            [$context, $item] = $this->args($event);
            if ($context !== 'com_media.file' || !\is_object($item) || !\is_string($item->data ?? null)) {
                return;
            }
            $ext = strtolower((string) ($item->extension ?? ''));
            if (!\in_array($ext, Optimizer::EXTENSIONS, true) || \strlen($item->data) < (int) $this->ctx()->setting('min_size_kb', 10) * 1024) {
                return;
            }
            $res = $this->optimizer()->process($item->data, $ext);
            $this->pending[spl_object_id($item)] = ['original' => $item->data, 'res' => $res];
            if ($res['changed']) {
                $item->data = $res['data'];
            }
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    /** onContentAfterSave (com_media.file): backup, siblings and the row, once the final file name is known. */
    public function onAfterSave(Event $event): void
    {
        try {
            [$context, $item] = $this->args($event);
            if ($context !== 'com_media.file' || !\is_object($item)) {
                return;
            }
            $state = $this->pending[spl_object_id($item)] ?? null;
            unset($this->pending[spl_object_id($item)]);
            if ($state === null) {
                return;
            }
            $rel = $this->relFromItem($item);
            $abs = $rel === '' ? null : Optimizer::abs($this->ctx()->db(), $rel);
            if ($abs === null || (int) filesize($abs) !== \strlen((string) $item->data)) {
                return; // not a local file, or someone else changed it in between
            }
            $this->optimizer()->persist($rel, $abs, $state['res'], $state['original'], 'upload', false);
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    public function onAfterDelete(Event $event): void
    {
        try {
            [$context, $item] = $this->args($event);
            if (!\in_array($context, ['com_media.file', 'com_media.folder'], true) || !\is_object($item)) {
                return;
            }
            $rel = $this->relFromItem($item);
            if ($rel === '') {
                return;
            }
            if ($context === 'com_media.folder') {
                $this->optimizer()->forgetFolder($rel);
            } else {
                $this->optimizer()->forget($rel);
            }
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    // ------------------------------------------------------------------ shared

    /** Runs one batch over the images folder. @return array{processed:int,optimized:int,skipped:int,failed:int,saved:int,done:bool,errors:string[]} */
    public function runBatch(int $limit, float $seconds): array
    {
        $limit    = max(1, min(50, $limit));
        $deadline = microtime(true) + $seconds;
        @set_time_limit((int) $seconds + 30);

        $bulk  = new Bulk($this->ctx()->db(), $this->store(), $this->ctx()->settings());
        $paths = $bulk->next($limit);
        $out   = ['processed' => 0, 'optimized' => 0, 'skipped' => 0, 'failed' => 0, 'saved' => 0, 'done' => \count($paths) < $limit, 'errors' => []];
        $opt   = $this->optimizer();
        foreach ($paths as $rel) {
            if (microtime(true) > $deadline) {
                $out['done'] = false;
                break;
            }
            try {
                $r = $opt->optimizeFile($rel, 'bulk', false, $this->ctx()->db());
            } catch (\Throwable $e) {
                // Recorded as failed so the file is not pending any more (otherwise every batch returns it again).
                $out['processed']++;
                $out['failed']++;
                $out['errors'][] = $rel . ': ' . $e->getMessage();
                try {
                    $opt->markFailed($rel, $e instanceof \Ux1\UxStudio\Core\ApiException ? $e->errorCode() : 'error', $this->ctx()->db());
                } catch (\Throwable) {
                }
                continue;
            }
            $out['processed']++;
            if ($r['status'] === 'optimized') {
                $out['optimized']++;
                $out['saved'] += max(0, (int) $r['original_size'] - (int) $r['file_size']);
            } elseif ($r['status'] === 'failed') {
                $out['failed']++;
            } elseif ($r['status'] === 'skipped') {
                $out['skipped']++;
            }
        }

        return $out;
    }

    public function optimizer(): Optimizer
    {
        return new Optimizer($this->ctx()->settings(), $this->store());
    }

    public function store(): Store
    {
        return new Store($this->ctx()->db());
    }

    public function context(): ModuleContext
    {
        return $this->ctx();
    }

    public function syncDelivery(): void
    {
        $caps = Optimizer::capabilities((string) $this->ctx()->setting('library', 'auto'));
        Delivery::sync(
            (bool) $this->ctx()->setting('htaccess_delivery', false),
            (bool) $this->ctx()->setting('convert_webp', true) && $caps['formats']['webp'],
            (bool) $this->ctx()->setting('convert_avif', false) && $caps['formats']['avif']
        );
    }

    public static function bytes(int $n): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i     = 0;
        $v     = (float) $n;
        while ($v >= 1024 && $i < 3) {
            $v /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $v : number_format($v, 1, '.', '')) . ' ' . $units[$i];
    }

    /** @return array{0:mixed,1:mixed} context and item of a content event (named or positional arguments) */
    private function args(Event $event): array
    {
        $a = array_values($event->getArguments());

        return [$a[0] ?? null, $a[1] ?? null];
    }

    /** Site-relative path of a Media Manager file item (adapter local-<directory>, path, name) or '' for other adapters. */
    private function relFromItem(object $item): string
    {
        $adapter = (string) ($item->adapter ?? '');
        if (!preg_match('#^local-(.+)$#', $adapter, $m)) {
            return '';
        }
        $name = (string) ($item->name ?? '');
        $path = trim((string) ($item->path ?? ''), '/');
        // On delete the item carries only the full path (no name).
        if ($name === '') {
            return trim($m[1], '/') . '/' . $path;
        }

        return trim($m[1], '/') . '/' . ($path === '' ? '' : $path . '/') . $name;
    }

    private function log(\Throwable $e): void
    {
        try {
            \Joomla\CMS\Log\Log::add('image-optimizer: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');
        } catch (\Throwable) {
        }
    }
}

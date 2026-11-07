<?php

namespace Ux1\UxStudio\Modules\ImageOptimizer;

use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;

\defined('_JEXEC') or die;

/** API routes of the image optimizer (all need core.manage on com_uxstudio and the CSRF token, see the core). */
final class Api
{
    public function __construct(private Module $module)
    {
    }

    /** GET image-optimizer/overview */
    public function overview(ApiRequest $r): array
    {
        $ctx  = $this->module->context();
        $caps = Optimizer::capabilities((string) $ctx->setting('library', 'auto'));

        return [
            'totals'       => $this->module->store()->totals(),
            'capabilities' => $caps,
            'delivery'     => [
                'enabled' => (bool) $ctx->setting('htaccess_delivery', false),
                'active'  => Delivery::isActive(),
            ],
            'bulk_enabled' => (bool) $ctx->setting('bulk_enabled', false),
            'auto'         => (bool) $ctx->setting('auto_optimize', true),
        ];
    }

    /** GET image-optimizer/scan - how many images are in the folder and how many are still to do. */
    public function scan(ApiRequest $r): array
    {
        @set_time_limit(60);

        return (new Bulk($this->module->context()->db(), $this->module->store(), $this->module->context()->settings()))->scan();
    }

    /** GET image-optimizer/items?page=&status=&q= */
    public function items(ApiRequest $r): array
    {
        $page   = max(1, $r->int('page', 1));
        $per    = max(5, min(100, $r->int('per', 25)));
        $result = $this->module->store()->page($page, $per, $r->string('status'), mb_substr($r->string('q'), 0, 100));

        return ['items' => $result['items'], 'total' => $result['total'], 'page' => $page, 'per' => $per];
    }

    /** POST image-optimizer/bulk/run {limit} - one batch now. */
    public function bulkRun(ApiRequest $r): array
    {
        return $this->module->runBatch($r->int('limit', (int) $this->module->context()->setting('bulk_batch', 10)), 20.0);
    }

    /** POST image-optimizer/optimize {path, force} */
    public function optimize(ApiRequest $r): array
    {
        $path = $this->path($r);
        $row  = $this->module->optimizer()->optimizeFile($path, 'manual', $r->bool('force'), $this->module->context()->db());

        return ['row' => $row];
    }

    /** POST image-optimizer/restore {path} */
    public function restore(ApiRequest $r): array
    {
        return ['row' => $this->module->optimizer()->restore($this->path($r), $this->module->context()->db())];
    }

    private function path(ApiRequest $r): string
    {
        $path = trim($r->string('path'));
        if ($path === '' || mb_strlen($path) > 700) {
            throw new ApiException(Text::_('UXS_IMAGE_OPTIMIZER_ERR_PATH'), 422, 'invalid_path');
        }

        return $path;
    }
}

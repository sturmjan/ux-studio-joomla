<?php

namespace Ux1\Module\UxStudioReviews\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Modules\ReviewAggregator\Module as ReviewAggregator;

\defined('_JEXEC') or die;

/**
 * Front-end module of the UX Studio "Review Aggregator": data, defaults and schema settings come from UX Studio
 * (module review-aggregator); the module parameters only override what differs for this position.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data         = parent::getLayoutData();
        $data['html'] = '';

        // UX Studio library missing or the aggregator is switched off: render nothing.
        if (!class_exists(Kernel::class)) {
            return $data;
        }
        $registry = Kernel::instance()->registry();
        if (!$registry->isEnabled('review-aggregator')) {
            return $data;
        }
        $module = $registry->module('review-aggregator');
        if (!$module instanceof ReviewAggregator) {
            return $data;
        }

        /** @var \Joomla\Registry\Registry $params */
        $params = $data['params'];
        try {
            $data['html'] = $module->renderFront([
                'heading'    => (string) $params->get('heading', ''),
                'layout'     => (string) $params->get('layout_mode', ''),
                'count'      => (int) $params->get('count', 0) ?: '',
                'min_rating' => (string) $params->get('min_rating', ''),
                'order'      => (string) $params->get('order', ''),
                'source'     => (string) $params->get('source', ''),
                'schema'     => (string) $params->get('schema', ''),
                'css_class'  => trim((string) $params->get('moduleclass_sfx', '')),
            ]);
        } catch (\Throwable $e) {
            $data['html'] = '';
        }

        return $data;
    }
}

<?php

namespace Ux1\Module\UxStudioOpeningHours\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Modules\OpeningHours\Module as OpeningHours;

\defined('_JEXEC') or die;

/**
 * Front-end module of the UX Studio "Opening Hours": locations, hours and colours come from UX Studio (module
 * opening-hours); the module parameters only choose which location is shown and how.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data         = parent::getLayoutData();
        $data['html'] = '';

        // UX Studio library missing or the module is switched off: render nothing.
        if (!class_exists(Kernel::class)) {
            return $data;
        }
        $registry = Kernel::instance()->registry();
        if (!$registry->isEnabled('opening-hours')) {
            return $data;
        }
        $module = $registry->module('opening-hours');
        if (!$module instanceof OpeningHours) {
            return $data;
        }

        /** @var \Joomla\Registry\Registry $params */
        $params   = $data['params'];
        $location = (string) $params->get('location', '0');
        try {
            $data['html'] = $module->renderFront([
                'location'  => $location === 'all' ? 'all' : (int) $location,
                'display'   => (string) $params->get('display', 'card'),
                'css_class' => trim((string) $params->get('moduleclass_sfx', '')),
            ]);
        } catch (\Throwable $e) {
            $data['html'] = '';
        }

        return $data;
    }
}

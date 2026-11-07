<?php

namespace Ux1\Module\UxStudioDashboard\Administrator\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Modules\DashboardWidgets\Module as DashboardWidgets;

\defined('_JEXEC') or die;

/**
 * Administrator module for the Joomla home screen (position "cpanel"): the cards come from UX Studio (module
 * dashboard-widgets); the module itself has no parameters of its own beyond layout and cache.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data          = parent::getLayoutData();
        $data['cards'] = [];

        // UX Studio library missing or the module is switched off: render nothing.
        if (!class_exists(Kernel::class)) {
            return $data;
        }
        $registry = Kernel::instance()->registry();
        if (!$registry->isEnabled('dashboard-widgets')) {
            return $data;
        }
        $module = $registry->module('dashboard-widgets');
        $user   = $this->getApplication()->getIdentity();
        if (!$module instanceof DashboardWidgets || $user === null || $user->guest) {
            return $data;
        }

        try {
            if ($module->showInCpanel()) {
                $data['cards'] = $module->cardsFor($user);
                if ($data['cards']) {
                    $module->useAssets();
                }
            }
        } catch (\Throwable $e) {
            $data['cards'] = [];
        }

        return $data;
    }
}

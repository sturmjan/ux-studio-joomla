<?php

namespace Ux1\Module\UxStudioForm\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\Lang;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\Forms\Renderer;

\defined('_JEXEC') or die;

/**
 * Front-end module that shows one form of the UX1 Studio "forms" module (chosen in the module parameters).
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data         = parent::getLayoutData();
        $data['html'] = '';

        // UX1 Studio library missing or the forms module is switched off: render nothing.
        if (!class_exists(Kernel::class)) {
            return $data;
        }
        $kernel   = Kernel::instance();
        $registry = $kernel->registry();
        if (!$registry->isEnabled('forms')) {
            return $data;
        }
        $module = $registry->module('forms');
        if (!$module) {
            return $data;
        }

        try {
            Lang::loadModule($this->app, 'forms', $module->path());
            $id           = (int) $data['params']->get('form_id', 0);
            $data['html'] = $id > 0 ? Renderer::render(new ModuleContext('forms', $this->app, $kernel), $id) : '';
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('mod_uxstudio_form: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'uxstudio');
            $data['html'] = '';
        }

        return $data;
    }
}

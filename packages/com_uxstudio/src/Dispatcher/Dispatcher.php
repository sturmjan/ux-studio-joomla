<?php

namespace Ux1\Component\UxStudio\Administrator\Dispatcher;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\Lang;

\defined('_JEXEC') or die;

/**
 * Admin dispatcher: access check (core.manage, inherited), core language and tables (idempotent self-heal after dev-link).
 */
class Dispatcher extends ComponentDispatcher
{
    protected function checkAccess(): void
    {
        parent::checkAccess();

        if (!class_exists(Kernel::class)) {
            throw new \RuntimeException(Text::_('COM_UXSTUDIO_ERR_LIBRARY_MISSING'), 500);
        }
        $kernel = Kernel::instance();
        Lang::loadCore($this->app);
        $kernel->migrator()->ensureCore();
    }
}

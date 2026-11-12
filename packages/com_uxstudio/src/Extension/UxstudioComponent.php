<?php

namespace Ux1\Component\UxStudio\Administrator\Extension;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Factory;
use Psr\Container\ContainerInterface;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Component extension class. Makes sure the UX1 Studio kernel is booted even if the system plugin is disabled.
 */
class UxstudioComponent extends MVCComponent implements BootableExtensionInterface
{
    public function boot(ContainerInterface $container): void
    {
        if (class_exists(Kernel::class)) {
            Kernel::instance()->boot(Factory::getApplication());
        }
    }
}

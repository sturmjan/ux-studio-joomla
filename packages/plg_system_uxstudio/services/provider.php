<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Ux1\Plugin\System\UxStudio\Extension\UxStudio;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // No dispatcher argument: passing it is deprecated since Joomla 5.4 and unsupported in 7.0.
                $plugin = new UxStudio((array) PluginHelper::getPlugin('system', 'uxstudio'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Install script of the pkg_uxstudio package: runs after ALL extensions of the package are installed.
 * Enables the UX Studio plugins (Joomla installs plugins disabled) and applies pending migrations.
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class (Factory::getApplication()) implements InstallerScriptInterface {
                public function __construct(private CMSApplicationInterface $app)
                {
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

                    // Enable the plugins only on the first install, so a later update never re-enables something the admin turned off.
                    if ($type === 'install' || $type === 'discover_install') {
                        $query = $db->getQuery(true)
                            ->update($db->quoteName('#__extensions'))
                            ->set($db->quoteName('enabled') . ' = 1')
                            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                            ->where($db->quoteName('element') . ' = ' . $db->quote('uxstudio'))
                            ->where($db->quoteName('folder') . ' IN (' . $db->quote('system') . ',' . $db->quote('task') . ')');
                        $db->setQuery($query)->execute();
                    }

                    spl_autoload_register(static function (string $class): void {
                        $prefix = 'Ux1\\UxStudio\\';
                        if (str_starts_with($class, $prefix)) {
                            $file = JPATH_LIBRARIES . '/uxstudio/src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                            if (is_file($file)) {
                                require_once $file;
                            }
                        }
                    });

                    if (class_exists(\Ux1\UxStudio\Core\Kernel::class)) {
                        try {
                            $kernel = \Ux1\UxStudio\Core\Kernel::instance();
                            $kernel->migrator()->runAll();
                            $kernel->scheduler()->syncCore();
                        } catch (\Throwable $e) {
                            $this->app->enqueueMessage('UX Studio migration failed: ' . $e->getMessage(), 'error');

                            return false;
                        }
                    }

                    return true;
                }
            }
        );
    }
};

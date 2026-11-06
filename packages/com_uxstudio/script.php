<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Install script of com_uxstudio: requirement check and database migrations of the UX Studio core.
 * (No SQL files in manifests - the Migrator in the library owns the schema.)
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class (Factory::getApplication()) implements InstallerScriptInterface {
                private const MIN_PHP = '8.1.0';
                private const MIN_JOOMLA = '5.0.0';

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
                    // Data tables are intentionally kept (uninstall must not destroy settings and logs).
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }
                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $this->app->enqueueMessage('UX Studio requires PHP ' . self::MIN_PHP . ' or newer.', 'error');

                        return false;
                    }
                    if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $this->app->enqueueMessage('UX Studio requires Joomla ' . self::MIN_JOOMLA . ' or newer.', 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    // The library may have been installed in this same request: make its namespace loadable.
                    spl_autoload_register(static function (string $class): void {
                        $prefix = 'Ux1\\UxStudio\\';
                        if (str_starts_with($class, $prefix)) {
                            $file = JPATH_LIBRARIES . '/uxstudio/src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
                            if (is_file($file)) {
                                require_once $file;
                            }
                        }
                    });

                    if (!class_exists(\Ux1\UxStudio\Core\Kernel::class)) {
                        $this->app->enqueueMessage('UX Studio library is missing, migrations were skipped.', 'warning');

                        return true;
                    }

                    try {
                        $kernel = \Ux1\UxStudio\Core\Kernel::instance();
                        $kernel->migrator()->runAll();
                        $kernel->scheduler()->syncCore();
                    } catch (\Throwable $e) {
                        $this->app->enqueueMessage('UX Studio migration failed: ' . $e->getMessage(), 'error');

                        return false;
                    }

                    return true;
                }
            }
        );
    }
};

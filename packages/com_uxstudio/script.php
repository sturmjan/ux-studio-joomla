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

                    return $this->refuseWhenLinked();
                }

                /**
                 * Dev-link guard: the installer removes the old files of an extension before copying the new ones.
                 * When a folder is a symlink / junction into the git repository (bin/dev-link.mjs), that would delete
                 * the repository files. Returns the list of linked folders.
                 *
                 * @return string[]
                 */
                private function linkedFolders(): array
                {
                    $candidates = [
                        JPATH_LIBRARIES . '/uxstudio',
                        JPATH_ADMINISTRATOR . '/components/com_uxstudio',
                        JPATH_ROOT . '/components/com_uxstudio',
                        JPATH_ROOT . '/media/com_uxstudio',
                        JPATH_ROOT . '/media/lib_uxstudio',
                        JPATH_ROOT . '/plugins/system/uxstudio',
                        JPATH_ROOT . '/plugins/task/uxstudio',
                    ];
                    foreach (['/modules', '/administrator/modules', '/media'] as $base) {
                        foreach (glob(JPATH_ROOT . $base . '/mod_uxstudio_*', GLOB_ONLYDIR) ?: [] as $dir) {
                            $candidates[] = $dir;
                        }
                    }

                    $linked = [];
                    foreach ($candidates as $path) {
                        if (!file_exists($path) && !is_link($path)) {
                            continue;
                        }
                        $real = realpath($path);
                        $norm = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
                        if (is_link($path) || $real === false || $norm($real) !== $norm($path)) {
                            $linked[] = $path;
                        }
                    }

                    return $linked;
                }

                private function refuseWhenLinked(): bool
                {
                    $linked = $this->linkedFolders();
                    if (!$linked) {
                        return true;
                    }
                    $this->app->enqueueMessage(
                        'UX Studio is linked to a git repository (bin/dev-link.mjs), installing a package here would delete the repository files. '
                        . 'Run "node bin/dev-link.mjs --unlink" first. Linked folders: ' . implode(', ', array_map('basename', $linked)),
                        'error'
                    );

                    return false;
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

                    // Stale classes may be loaded (update): migrations and scheduler sync run lazily in the next admin request (Core\Upgrader).
                    try {
                        $kernel = \Ux1\UxStudio\Core\Kernel::instance();
                        $kernel->migrator()->ensureCore();
                        \Ux1\UxStudio\Core\Upgrader::markDirty($kernel->db());
                    } catch (\Throwable $e) {
                        $this->app->enqueueMessage('UX Studio setup failed: ' . $e->getMessage(), 'error');

                        return false;
                    }

                    return true;
                }
            }
        );
    }
};

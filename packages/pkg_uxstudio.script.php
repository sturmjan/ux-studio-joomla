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
 * Enables the UX1 Studio plugins (Joomla installs plugins disabled) and applies pending migrations.
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
                    // The routine "uxstudio.run" disappears with the task plugin: remove our scheduler rows, otherwise Joomla keeps
                    // them as orphaned tasks and mails the administrators on every scheduler run.
                    // The data tables #__uxstudio_* are intentionally kept (settings, logs); see README.
                    try {
                        $db   = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
                        $type = 'uxstudio.run';
                        $db->setQuery(
                            $db->createQuery()->delete($db->quoteName('#__scheduler_tasks'))->where($db->quoteName('type') . ' = :t')->bind(':t', $type)
                        )->execute();
                    } catch (\Throwable $e) {
                        // com_scheduler may be absent.
                    }

                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
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
                        'UX1 Studio is linked to a git repository (bin/dev-link.mjs), installing a package here would delete the repository files. '
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

                    $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

                    // Enable the plugins only on the first install, so a later update never re-enables something the admin turned off.
                    if ($type === 'install' || $type === 'discover_install') {
                        $query = $db->createQuery()
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

                    // Do NOT migrate here: plg_system_uxstudio already loaded the OLD classes in this request, so new migrations and
                    // scheduler jobs would be missed. Only make sure the core tables exist and flag the next admin request,
                    // which runs Core\Upgrader with the freshly installed classes.
                    if (class_exists(\Ux1\UxStudio\Core\Kernel::class)) {
                        try {
                            \Ux1\UxStudio\Core\Kernel::instance()->migrator()->ensureCore();
                            \Ux1\UxStudio\Core\Upgrader::markDirty($db);
                        } catch (\Throwable $e) {
                            $this->app->enqueueMessage('UX1 Studio setup failed: ' . $e->getMessage(), 'error');

                            return false;
                        }
                    }

                    return true;
                }
            }
        );
    }
};

<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Entry point of the UX1 Studio core. plg_system_uxstudio calls Kernel::instance()->boot($app) in onAfterInitialise
 * (site, administrator, cli); every enabled module then gets boot(ModuleContext).
 */
final class Kernel
{
    private static ?Kernel $instance = null;

    private ?CMSApplicationInterface $app = null;
    private bool $booted = false;
    private ?DatabaseInterface $db = null;
    private ?ModuleRegistry $registry = null;
    private ?Settings $settings = null;
    private ?Migrator $migrator = null;
    private ?Scheduler $scheduler = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        Log::addLogger(['text_file' => 'uxstudio.php', 'text_entry_format' => '{DATETIME} {PRIORITY} {MESSAGE}'], Log::ALL, ['uxstudio']);
    }

    public function db(): DatabaseInterface
    {
        return $this->db ??= Factory::getContainer()->get(DatabaseInterface::class);
    }

    public function app(): CMSApplicationInterface
    {
        return $this->app ?? Factory::getApplication();
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry ??= new ModuleRegistry($this->db(), __DIR__ . '/../Modules');
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db(), $this->registry());
    }

    public function migrator(): Migrator
    {
        return $this->migrator ??= new Migrator($this->db(), $this->registry());
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler ??= new Scheduler($this->db(), $this->registry(), $this);
    }

    public function retention(): Retention
    {
        return new Retention($this->db(), $this->registry());
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** Version of the installed library (read from its manifest), 'dev' when unknown. */
    public static function version(): string
    {
        static $version = null;
        if ($version === null) {
            $version = 'dev';
            $file    = JPATH_ADMINISTRATOR . '/manifests/libraries/uxstudio.xml';
            if (is_file($file) && ($xml = @simplexml_load_file($file)) && isset($xml->version)) {
                $version = (string) $xml->version;
            }
        }

        return $version;
    }

    /** Idempotent. Never throws: a broken module must not take the site down. */
    public function boot(CMSApplicationInterface $app): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->app    = $app;

        try {
            Lang::loadCore($app);
            Notices::register($app);
            foreach ($this->registry()->enabledIds() as $id) {
                try {
                    $module = $this->registry()->module($id);
                    if (!$module) {
                        continue;
                    }
                    Lang::loadModule($app, $id, $module->path());
                    $ctx = new ModuleContext($id, $app, $this);
                    $module->setContext($ctx);
                    $module->boot($ctx);
                    $this->registry()->bootSucceeded($id);
                } catch (\Throwable $e) {
                    // One broken module never stops the boot of the others; the error is logged and shown in the SPA.
                    $this->registry()->recordError($id, $e);
                }
            }
        } catch (\Throwable $e) {
            Log::add('UX1 Studio boot failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');
        }

        // After an update (or a dev-link of new code): pending migrations and scheduler sync, with fresh classes.
        if ($app->isClient('administrator') || $app->isClient('cli')) {
            try {
                (new Upgrader($this))->runIfNeeded();
            } catch (\Throwable $e) {
                Log::add('UX1 Studio upgrade step failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');
                // The detail (SQL, table names) goes to the log only; the message is shown to a signed-in manager and is generic.
                if ($app->isClient('administrator')) {
                    $me = $app->getIdentity();
                    if ($me && !$me->guest && $me->authorise('core.manage', 'com_uxstudio')) {
                        $app->enqueueMessage('UX1 Studio: the update step failed, see the "uxstudio" log.', 'error');
                    }
                }
            }
        }
    }
}

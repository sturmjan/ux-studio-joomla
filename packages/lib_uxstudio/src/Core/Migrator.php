<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Applies schema migrations. The core owns its tables (module "core"); modules return migrations() from their class.
 * Applied versions are recorded in #__uxstudio_migrations (module, version, applied_at).
 */
final class Migrator
{
    public const CORE = 'core';

    private const TABLE_OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

    private static bool $coreChecked = false;

    public function __construct(private DatabaseInterface $db, private ModuleRegistry $registry)
    {
    }

    /** @return array<int,string[]> */
    private function coreMigrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_modules` ('
                . '`id` VARCHAR(64) NOT NULL, `enabled` TINYINT(1) NOT NULL DEFAULT 0, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id`))' . self::TABLE_OPTIONS,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_settings` ('
                . '`module` VARCHAR(64) NOT NULL, `data` MEDIUMTEXT NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`module`))' . self::TABLE_OPTIONS,
            ],
            2 => [
                // Fixed-window counters of the public endpoint (Core\RateLimiter).
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_ratelimit` ('
                . '`bucket` CHAR(40) NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 0, `window_start` INT UNSIGNED NOT NULL, PRIMARY KEY (`bucket`), KEY `idx_window` (`window_start`))' . self::TABLE_OPTIONS,
            ],
        ];
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->setQuery(
            'CREATE TABLE IF NOT EXISTS `#__uxstudio_migrations` ('
            . '`module` VARCHAR(64) NOT NULL, `version` INT UNSIGNED NOT NULL, `applied_at` DATETIME NOT NULL, PRIMARY KEY (`module`, `version`))'
            . self::TABLE_OPTIONS
        )->execute();
    }

    /** Creates / upgrades the core tables once per request. Cheap when up to date (one query). */
    public function ensureCore(): void
    {
        if (self::$coreChecked) {
            return;
        }
        $this->ensureMigrationsTable();
        $this->apply(self::CORE, $this->coreMigrations());
        self::$coreChecked = true;
    }

    /** @return int[] versions applied by the module's migrations just now */
    public function run(string $moduleId): array
    {
        $this->ensureCore();
        $module = $this->registry->module($moduleId);

        return $module ? $this->apply($moduleId, $module->migrations()) : [];
    }

    /** Core + every module (enabled or not, so that update of the package keeps tables current). @return array<string,int[]> */
    public function runAll(bool $onlyEnabled = true): array
    {
        $this->ensureCore();
        $done = [];
        $ids  = $onlyEnabled ? $this->registry->enabledIds() : array_keys($this->registry->all());
        foreach ($ids as $id) {
            $applied = $this->run($id);
            if ($applied) {
                $done[$id] = $applied;
            }
        }

        return $done;
    }

    /** @return int[] versions of $moduleId that are already recorded */
    public function appliedVersions(string $moduleId): array
    {
        $db = $this->db;

        return array_map('intval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('version'))->from($db->quoteName('#__uxstudio_migrations'))
                ->where($db->quoteName('module') . ' = :m')->bind(':m', $moduleId)
        )->loadColumn() ?: []);
    }

    /**
     * @param array<int,string[]> $migrations
     * @return int[]
     */
    private function apply(string $moduleId, array $migrations): array
    {
        if (!$migrations) {
            return [];
        }
        ksort($migrations);
        $applied = $this->appliedVersions($moduleId);
        $done    = [];

        foreach ($migrations as $version => $statements) {
            $version = (int) $version;
            if (\in_array($version, $applied, true)) {
                continue;
            }
            foreach ((array) $statements as $sql) {
                try {
                    $this->db->setQuery((string) $sql)->execute();
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf('Migration %s v%d failed: %s', $moduleId, $version, $e->getMessage()), 0, $e);
                }
            }
            $now = Factory::getDate()->toSql();
            $this->db->setQuery(
                $this->db->createQuery()->insert($this->db->quoteName('#__uxstudio_migrations'))
                    ->columns($this->db->quoteName(['module', 'version', 'applied_at']))->values(':m, :v, :t')
                    ->bind(':m', $moduleId)->bind(':v', $version, ParameterType::INTEGER)->bind(':t', $now)
            )->execute();
            $done[] = $version;
        }

        return $done;
    }
}

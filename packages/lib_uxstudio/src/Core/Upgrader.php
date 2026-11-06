<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Lazy "after update" work: pending migrations and scheduler synchronisation.
 *
 * The installer request has the OLD module classes in memory (plg_system_uxstudio booted them before the update),
 * so the install scripts only call markDirty(). The next administrator / CLI request runs runIfNeeded() with fresh
 * classes: when the fingerprint (library version, module files, migration versions, declared jobs) differs from the
 * stored one, it applies migrations and reconciles #__scheduler_tasks with tasks() of every enabled module.
 */
final class Upgrader
{
    /** Row in #__uxstudio_settings that stores the fingerprint (not a module id: modules match ^[a-z]). */
    private const STATE_KEY = '_core';

    public function __construct(private Kernel $kernel)
    {
    }

    /**
     * Forces the next runIfNeeded() to run. Static and independent of the other core classes, so install scripts
     * can call it even when stale classes are loaded. Silently does nothing before the tables exist.
     */
    public static function markDirty(DatabaseInterface $db): void
    {
        try {
            self::store($db, '');
        } catch (\Throwable $e) {
            // Settings table missing: the first runIfNeeded() has no stored fingerprint anyway.
        }
    }

    /** @return array{migrations:array<string,int[]>}|null null when nothing was to do */
    public function runIfNeeded(): ?array
    {
        $db = $this->kernel->db();
        $this->kernel->migrator()->ensureCore();

        $current = $this->fingerprint();
        if (self::stored($db) === $current) {
            return null;
        }

        $migrations = $this->kernel->migrator()->runAll();
        $this->kernel->scheduler()->syncAll();
        self::store($db, $current);

        return ['migrations' => $migrations];
    }

    private function fingerprint(): string
    {
        $registry = $this->kernel->registry();
        $parts    = [Kernel::version()];

        foreach ($registry->all() as $id => $meta) {
            $dir     = (string) $meta['path'];
            $parts[] = $id . ':' . (int) @filemtime($dir . '/Module.php') . ':' . (int) @filemtime($dir . '/meta.json');
        }
        foreach ($registry->enabledIds() as $id) {
            $module = $registry->module($id);
            if (!$module) {
                continue;
            }
            $parts[] = $id . ':m:' . implode(',', array_keys($module->migrations()));
            $parts[] = $id . ':t:' . $this->kernel->scheduler()->signatureOf($module);
        }

        return sha1(implode("\n", $parts));
    }

    private static function stored(DatabaseInterface $db): string
    {
        $key  = self::STATE_KEY;
        $json = $db->setQuery(
            $db->createQuery()->select($db->quoteName('data'))->from($db->quoteName('#__uxstudio_settings'))
                ->where($db->quoteName('module') . ' = :m')->bind(':m', $key)
        )->loadResult();
        $data = json_decode((string) $json, true);

        return \is_array($data) ? (string) ($data['fingerprint'] ?? '') : '';
    }

    private static function store(DatabaseInterface $db, string $fingerprint): void
    {
        $key  = self::STATE_KEY;
        $json = json_encode(['fingerprint' => $fingerprint], JSON_UNESCAPED_SLASHES);
        $now  = Factory::getDate()->toSql();

        $exists = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_settings'))->where($db->quoteName('module') . ' = :m')->bind(':m', $key)
        )->loadResult();

        if ($exists) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_settings'))
                ->set($db->quoteName('data') . ' = :d')->set($db->quoteName('updated_at') . ' = :ts')
                ->where($db->quoteName('module') . ' = :m')
                ->bind(':d', $json)->bind(':ts', $now)->bind(':m', $key);
        } else {
            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_settings'))
                ->columns($db->quoteName(['module', 'data', 'updated_at']))->values(':m, :d, :ts')
                ->bind(':m', $key)->bind(':d', $json)->bind(':ts', $now);
        }
        $db->setQuery($q)->execute();
    }
}

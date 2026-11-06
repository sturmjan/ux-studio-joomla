<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Auto-discovers modules from src/Modules/<Name>/meta.json (no central list) and keeps their enabled flag.
 */
final class ModuleRegistry
{
    /** @var array<string,array<string,mixed>>|null id => meta */
    private ?array $defs = null;

    /** @var array<string,BaseModule> */
    private array $instances = [];

    /** @var array<string,bool>|null */
    private ?array $enabled = null;

    public function __construct(private DatabaseInterface $db, private string $modulesDir)
    {
    }

    /** @return array<string,array<string,mixed>> id => meta (sorted by id) */
    public function all(): array
    {
        if ($this->defs !== null) {
            return $this->defs;
        }

        $defs = [];
        foreach (glob($this->modulesDir . '/*/meta.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (!\is_array($json) || !isset($json['id']) || !preg_match('/^[a-z][a-z0-9-]*$/', (string) $json['id'])) {
                continue;
            }
            $dir        = basename(\dirname($file));
            $json['dir']  = $dir;
            $json['path'] = \dirname($file);
            $json['class'] = 'Ux1\\UxStudio\\Modules\\' . $dir . '\\Module';
            $json += [
                'name'        => $json['id'],
                'description' => '',
                'group'       => 'developer',
                'icon'        => 'puzzle',
                'settings'    => false,
                'requires'    => [],
            ];
            $defs[$json['id']] = $json;
        }
        ksort($defs);

        return $this->defs = $defs;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function meta(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** Instantiates the module class (cached) or returns null when unknown / broken. */
    public function module(string $id): ?BaseModule
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $meta = $this->meta($id);
        if ($meta === null || !class_exists($meta['class'])) {
            return null;
        }
        $module = new $meta['class']();
        if (!$module instanceof BaseModule) {
            return null;
        }
        $module->setMeta($meta);

        return $this->instances[$id] = $module;
    }

    /** @return array<string,bool> */
    private function states(): array
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }
        $this->enabled = [];
        try {
            $rows = $this->db->setQuery(
                $this->db->createQuery()->select([$this->db->quoteName('id'), $this->db->quoteName('enabled')])->from($this->db->quoteName('#__uxstudio_modules'))
            )->loadAssocList();
            foreach ($rows as $row) {
                $this->enabled[$row['id']] = (bool) $row['enabled'];
            }
        } catch (\Throwable $e) {
            // Tables do not exist yet (before the first migration): nothing is enabled.
        }

        return $this->enabled;
    }

    public function isEnabled(string $id): bool
    {
        return $this->has($id) && ($this->states()[$id] ?? false);
    }

    /** @return string[] ids of enabled modules that still exist on disk */
    public function enabledIds(): array
    {
        return array_values(array_filter(array_keys($this->states()), fn (string $id) => $this->states()[$id] && $this->has($id)));
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $exists = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_modules'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id)
        )->loadResult();

        $flag = $enabled ? 1 : 0;
        if ($exists) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_modules'))
                ->set($db->quoteName('enabled') . ' = :en')->set($db->quoteName('updated_at') . ' = :ts')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':en', $flag, \Joomla\Database\ParameterType::INTEGER)->bind(':ts', $now)->bind(':id', $id);
        } else {
            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_modules'))
                ->columns($db->quoteName(['id', 'enabled', 'updated_at']))
                ->values(':id, :en, :ts')
                ->bind(':id', $id)->bind(':en', $flag, \Joomla\Database\ParameterType::INTEGER)->bind(':ts', $now);
        }
        $db->setQuery($q)->execute();
        $this->enabled = null;
    }

    /** @return string[] enabled modules that list $id in their "requires" */
    public function dependents(string $id): array
    {
        $out = [];
        foreach ($this->enabledIds() as $other) {
            if (\in_array($id, (array) ($this->meta($other)['requires'] ?? []), true)) {
                $out[] = $other;
            }
        }

        return $out;
    }
}

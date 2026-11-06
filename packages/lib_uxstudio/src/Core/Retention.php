<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Deletes old rows of module log tables. Modules declare retention(): [['table' => '#__uxstudio_x', 'column' => 'created', 'days' => 90]].
 * 'days' may be an int or a callable returning an int (e.g. read from the module settings); 0 or less disables cleanup.
 */
final class Retention
{
    public function __construct(private DatabaseInterface $db, private ModuleRegistry $registry)
    {
    }

    /** @return array<string,int> "<module>:<table>" => deleted rows */
    public function run(): array
    {
        $report = [];
        foreach ($this->registry->enabledIds() as $id) {
            $module = $this->registry->module($id);
            if (!$module) {
                continue;
            }
            foreach ($module->retention() as $rule) {
                $table  = (string) ($rule['table'] ?? '');
                $column = (string) ($rule['column'] ?? '');
                $days   = $rule['days'] ?? 0;
                $days   = (int) (\is_callable($days) ? $days() : $days);

                // Only the module's own tables may be cleaned, identifiers are whitelisted by pattern.
                if ($days <= 0 || !preg_match('/^#__uxstudio_[a-z0-9_]+$/', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/', $column)) {
                    continue;
                }
                $cutoff = Factory::getDate('-' . $days . ' days')->toSql();
                try {
                    $query = $this->db->createQuery()->delete($this->db->quoteName($table))
                        ->where($this->db->quoteName($column) . ' < :cut')->bind(':cut', $cutoff);
                    $this->db->setQuery($query)->execute();
                    $report[$id . ':' . $table] = $this->db->getAffectedRows();
                } catch (\Throwable $e) {
                    $report[$id . ':' . $table] = -1;
                }
            }
        }

        return $report;
    }
}

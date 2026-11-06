<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Glue between module tasks() and the Joomla Scheduler (com_scheduler).
 * Every job is one row in #__scheduler_tasks of type "uxstudio.run" with params {"job":"<module>.<job>"}.
 * plg_task_uxstudio executes them through Scheduler::run().
 */
final class Scheduler
{
    public const ROUTINE = 'uxstudio.run';

    public function __construct(private DatabaseInterface $db, private ModuleRegistry $registry, private Kernel $kernel)
    {
    }

    /** @return array<string,array{label:string,callable:callable,interval:string}> job id => definition */
    private function jobsOf(BaseModule $module): array
    {
        $jobs = [];
        foreach ($module->tasks() as $jobId => $def) {
            if (!preg_match('/^[a-z0-9_-]+$/', (string) $jobId)) {
                continue;
            }
            $label    = (string) ($def['label'] ?? $def[0] ?? $jobId);
            $callable = $def['callable'] ?? $def[1] ?? null;
            $interval = $def['interval'] ?? $def[2] ?? 'PT24H';
            if (!\is_callable($callable)) {
                continue;
            }
            $jobs[(string) $jobId] = [
                'label'    => preg_match('/^[A-Z][A-Z0-9_]{3,}$/', $label) ? Text::_($label) : $label,
                'callable' => $callable,
                'interval' => self::normalizeInterval($interval),
            ];
        }

        return $jobs;
    }

    /** Accepts hours (int) or ISO 8601 durations PT15M, PT6H, P1D. */
    public static function normalizeInterval(mixed $interval): string
    {
        if (\is_int($interval) || (\is_string($interval) && ctype_digit($interval))) {
            return 'PT' . max(1, (int) $interval) . 'H';
        }
        if (\is_string($interval) && preg_match('/^(PT\d+[MH]|P\d+D)$/', $interval)) {
            return $interval;
        }

        return 'PT24H';
    }

    /** @return array{0:array<string,string>,1:string} execution_rules, cron_rules.exp */
    private static function rulesFor(string $interval): array
    {
        preg_match('/^P(T)?(\d+)([MHD])$/', $interval, $m);
        $n = (int) ($m[2] ?? 24);

        [$type, $field] = match ($m[3] ?? 'H') {
            'M' => ['interval-minutes', 'interval-minutes'],
            'D' => ['interval-days', 'interval-days'],
            default => ['interval-hours', 'interval-hours'],
        };

        return [['rule-type' => $type, $field => (string) $n, 'exec-day' => '01', 'exec-time' => '00:00'], $interval];
    }

    /** Create missing tasks / enable existing ones for a freshly enabled module. */
    public function enableModule(string $moduleId): void
    {
        $module = $this->registry->module($moduleId);
        if (!$module) {
            return;
        }
        foreach ($this->jobsOf($module) as $jobId => $def) {
            $this->ensureTask($moduleId . '.' . $jobId, 'UX Studio: ' . $def['label'], $def['interval'], true);
        }
    }

    public function disableModule(string $moduleId): void
    {
        $this->setStateForPrefix($moduleId . '.', 0);
    }

    /** Core housekeeping job (log retention), created on install / first migration. */
    public function syncCore(): void
    {
        $this->ensureTask('core.retention', 'UX Studio: Clean up old log records', 'PT24H', true);
    }

    private function ensureTask(string $job, string $title, string $interval, bool $enable): void
    {
        try {
            $db = $this->db;
            $type = self::ROUTINE;
            $like = '%"job":' . json_encode($job) . '%';
            $row = $db->setQuery(
                $db->getQuery(true)->select([$db->quoteName('id'), $db->quoteName('state')])->from($db->quoteName('#__scheduler_tasks'))
                    ->where($db->quoteName('type') . ' = :t')->where($db->quoteName('params') . ' LIKE :p')
                    ->bind(':t', $type)->bind(':p', $like)
            )->loadAssoc();

            if ($row) {
                if ($enable && (int) $row['state'] !== 1) {
                    $id = (int) $row['id'];
                    $db->setQuery($db->getQuery(true)->update($db->quoteName('#__scheduler_tasks'))->set($db->quoteName('state') . ' = 1')
                        ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
                }

                return;
            }

            [$exec, $exp] = self::rulesFor($interval);
            $now    = Factory::getDate();
            $next   = Factory::getDate('+' . self::minutesOf($interval) . ' minutes')->toSql();
            $params = json_encode([
                'job'           => $job,
                'individual_log' => false,
                'log_file'      => '',
                'notifications' => ['success_mail' => '0', 'failure_mail' => '1', 'fatal_failure_mail' => '1', 'orphan_mail' => '1'],
            ], JSON_UNESCAPED_SLASHES);
            $execJson = json_encode($exec);
            $cronJson = json_encode(['type' => 'interval', 'exp' => $exp]);
            $created  = $now->toSql();
            $state    = $enable ? 1 : 0;
            $userId   = (int) Factory::getApplication()->getIdentity()?->id;
            $type     = self::ROUTINE;

            $query = $db->getQuery(true)->insert($db->quoteName('#__scheduler_tasks'))
                ->columns($db->quoteName(['title', 'type', 'execution_rules', 'cron_rules', 'state', 'next_execution', 'params', 'created', 'created_by']))
                ->values(':title, :type, :exec, :cron, :state, :next, :params, :created, :by')
                ->bind(':title', $title)->bind(':type', $type)->bind(':exec', $execJson)->bind(':cron', $cronJson)
                ->bind(':state', $state, ParameterType::INTEGER)->bind(':next', $next)->bind(':params', $params)
                ->bind(':created', $created)->bind(':by', $userId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // com_scheduler tables missing / locked: jobs are optional, never break enabling a module.
            \Joomla\CMS\Log\Log::add('UX Studio scheduler sync failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');
        }
    }

    private static function minutesOf(string $interval): int
    {
        preg_match('/^P(T)?(\d+)([MHD])$/', $interval, $m);
        $n = (int) ($m[2] ?? 24);

        return match ($m[3] ?? 'H') {
            'M' => $n,
            'D' => $n * 1440,
            default => $n * 60,
        };
    }

    private function setStateForPrefix(string $prefix, int $state): void
    {
        try {
            $db   = $this->db;
            $like = '%"job":' . json_encode($prefix) . '%';
            // json_encode adds the closing quote; strip it to match any job of the module.
            $like = substr($like, 0, -2) . '%';
            $type = self::ROUTINE;
            $db->setQuery(
                $db->getQuery(true)->update($db->quoteName('#__scheduler_tasks'))->set($db->quoteName('state') . ' = :s')
                    ->where($db->quoteName('type') . ' = :t')->where($db->quoteName('params') . ' LIKE :p')
                    ->bind(':s', $state, ParameterType::INTEGER)->bind(':t', $type)->bind(':p', $like)
            )->execute();
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('UX Studio scheduler update failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');
        }
    }

    /**
     * Execute "<module>.<job>" (called by plg_task_uxstudio).
     *
     * @return string output text for the task log
     * @throws \RuntimeException when the job is unknown or the module is disabled
     */
    public function run(string $job): string
    {
        [$moduleId, $jobId] = array_pad(explode('.', $job, 2), 2, '');

        if ($moduleId === 'core' && $jobId === 'retention') {
            return json_encode((new Retention($this->db, $this->registry))->run(), JSON_UNESCAPED_SLASHES) ?: '';
        }
        if (!$this->registry->isEnabled($moduleId)) {
            throw new \RuntimeException('Module "' . $moduleId . '" is not enabled.');
        }
        $module = $this->registry->module($moduleId);
        $jobs   = $module ? $this->jobsOf($module) : [];
        if (!isset($jobs[$jobId])) {
            throw new \RuntimeException('Unknown job "' . $job . '".');
        }
        Lang::loadModule($this->kernel->app(), $moduleId, $module->path());
        $result = ($jobs[$jobId]['callable'])();

        return \is_scalar($result) ? (string) $result : (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}

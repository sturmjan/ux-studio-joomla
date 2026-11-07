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

    /**
     * One-off run: moves the next execution of the task of a job to now (+ delay), creating the task when it is missing.
     * The job still keeps its regular interval afterwards. Executed by the next scheduler tick.
     */
    public function runSoon(string $moduleId, string $jobId, int $delaySeconds = 0): bool
    {
        $module = $this->registry->module($moduleId);
        if (!$module || !$this->registry->isEnabled($moduleId)) {
            return false;
        }
        $jobs = $this->jobsOf($module);
        if (!isset($jobs[$jobId])) {
            return false;
        }
        $job = $moduleId . '.' . $jobId;
        try {
            $row = $this->findRow($job);
            if ($row === null) {
                $this->ensureTask($job, 'UX Studio: ' . $jobs[$jobId]['label'], $jobs[$jobId]['interval'], true);
                $row = $this->findRow($job);
            }
            if ($row === null || (int) $row['state'] !== 1) {
                // A task the administrator switched off in the Joomla scheduler stays off (their decision).
                return false;
            }
            $db   = $this->db;
            $id   = (int) $row['id'];
            $when = Factory::getDate('+' . max(0, $delaySeconds) . ' seconds')->toSql();
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__scheduler_tasks'))
                    ->set($db->quoteName('next_execution') . ' = :n')
                    ->where($db->quoteName('id') . ' = :id')->bind(':n', $when)->bind(':id', $id, ParameterType::INTEGER)
            )->execute();

            return true;
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('UX Studio scheduler runSoon failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'uxstudio');

            return false;
        }
    }

    /** @return array<string,mixed>|null the uxstudio.run row of "<module>.<job>" */
    private function findRow(string $job): ?array
    {
        foreach ($this->rows() as $row) {
            if (($row['params']['job'] ?? null) === $job && (int) $row['state'] !== -2) {
                return $row;
            }
        }

        return null;
    }

    /** Core housekeeping job (log retention), created on install / first migration. */
    public function syncCore(): void
    {
        $this->ensureTask('core.retention', 'UX Studio: Clean up old log records', 'PT24H', true);
    }

    /** Stable text describing the declared jobs of a module (ids, intervals, labels), used to detect changes after an update. */
    public function signatureOf(BaseModule $module): string
    {
        $parts = [];
        foreach ($module->tasks() as $jobId => $def) {
            $parts[] = $jobId . '=' . self::normalizeInterval($def['interval'] ?? $def[2] ?? 'PT24H') . '=' . (string) ($def['label'] ?? $def[0] ?? '');
        }
        sort($parts);

        return implode(';', $parts);
    }

    /**
     * Reconcile #__scheduler_tasks with the jobs declared NOW by an enabled module: create missing rows, update title
     * and interval when the module changed them (an interval edited by the admin is kept otherwise) and trash the
     * rows of jobs the module no longer declares, so the scheduler does not keep failing on them.
     */
    public function syncModule(string $moduleId): void
    {
        $module = $this->registry->module($moduleId);
        if (!$module) {
            return;
        }
        $declared = [];
        foreach ($this->jobsOf($module) as $jobId => $def) {
            $declared[$moduleId . '.' . $jobId] = true;
            $this->ensureTask($moduleId . '.' . $jobId, 'UX Studio: ' . $def['label'], $def['interval'], false, true);
        }
        foreach ($this->rows() as $row) {
            $job = (string) ($row['params']['job'] ?? '');
            if (str_starts_with($job, $moduleId . '.') && !isset($declared[$job]) && (int) $row['state'] !== -2) {
                $this->setRowState((int) $row['id'], -2);
            }
        }
    }

    /** Core job + every enabled module; tasks of modules that no longer exist on disk are trashed. */
    public function syncAll(): void
    {
        $this->syncCore();
        foreach ($this->registry->enabledIds() as $id) {
            $this->syncModule($id);
        }
        foreach ($this->rows() as $row) {
            $job    = (string) ($row['params']['job'] ?? '');
            $module = explode('.', $job, 2)[0];
            if ($module !== '' && $module !== 'core' && !$this->registry->has($module) && (int) $row['state'] !== -2) {
                $this->setRowState((int) $row['id'], -2);
            }
        }
    }

    /** @return array<int,array<string,mixed>> all uxstudio.run rows with decoded params */
    private function rows(): array
    {
        try {
            $db   = $this->db;
            $type = self::ROUTINE;
            $rows = $db->setQuery(
                $db->createQuery()->select($db->quoteName(['id', 'state', 'title', 'params']))->from($db->quoteName('#__scheduler_tasks'))
                    ->where($db->quoteName('type') . ' = :t')->bind(':t', $type)
            )->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as &$row) {
            $decoded       = json_decode((string) $row['params'], true);
            $row['params'] = \is_array($decoded) ? $decoded : [];
        }
        unset($row);

        return $rows;
    }

    private function setRowState(int $id, int $state): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__scheduler_tasks'))->set($db->quoteName('state') . ' = :s')
                ->where($db->quoteName('id') . ' = :id')->bind(':s', $state, ParameterType::INTEGER)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * @param bool $forceEnable set state to 1 for an existing row (module just enabled)
     * @param bool $reconcile   update title / interval of an existing row when the module changed them
     */
    private function ensureTask(string $job, string $title, string $interval, bool $forceEnable, bool $reconcile = false): void
    {
        try {
            $db  = $this->db;
            $row = null;
            foreach ($this->rows() as $candidate) {
                if (($candidate['params']['job'] ?? null) === $job) {
                    $row = $candidate;
                    break;
                }
            }

            if ($row) {
                $id  = (int) $row['id'];
                $set = [];
                if ($forceEnable && (int) $row['state'] !== 1) {
                    $set[] = $db->quoteName('state') . ' = 1';
                }
                if ($reconcile) {
                    $params          = $row['params'];
                    $changedInterval = isset($params['declared_interval']) && $params['declared_interval'] !== $interval;
                    $changedTitle    = isset($params['declared_title']) && $params['declared_title'] !== $title;
                    if (($params['declared_interval'] ?? null) !== $interval || ($params['declared_title'] ?? null) !== $title) {
                        $params['declared_interval'] = $interval;
                        $params['declared_title']    = $title;
                        $set[] = $db->quoteName('params') . ' = ' . $db->quote(json_encode($params, JSON_UNESCAPED_SLASHES));
                    }
                    if ($changedInterval) {
                        [$exec, $exp] = self::rulesFor($interval);
                        $set[] = $db->quoteName('execution_rules') . ' = ' . $db->quote(json_encode($exec));
                        $set[] = $db->quoteName('cron_rules') . ' = ' . $db->quote(json_encode(['type' => 'interval', 'exp' => $exp]));
                    }
                    if ($changedTitle) {
                        $set[] = $db->quoteName('title') . ' = ' . $db->quote($title);
                    }
                    if ((int) $row['state'] === -2) {
                        // The module declares the job again.
                        $set[] = $db->quoteName('state') . ' = 0';
                    }
                }
                if ($set) {
                    $db->setQuery(
                        $db->createQuery()->update($db->quoteName('#__scheduler_tasks'))->set($set)
                            ->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
                    )->execute();
                }

                return;
            }

            [$exec, $exp] = self::rulesFor($interval);
            $next   = Factory::getDate('+' . self::minutesOf($interval) . ' minutes')->toSql();
            $params = json_encode([
                'job'               => $job,
                'declared_interval' => $interval,
                'declared_title'    => $title,
                'individual_log'    => false,
                'log_file'          => '',
                'notifications'     => ['success_mail' => '0', 'failure_mail' => '1', 'fatal_failure_mail' => '1', 'orphan_mail' => '1'],
            ], JSON_UNESCAPED_SLASHES);
            $execJson = json_encode($exec);
            $cronJson = json_encode(['type' => 'interval', 'exp' => $exp]);
            $created  = Factory::getDate()->toSql();
            $state    = 1;
            $userId   = (int) Factory::getApplication()->getIdentity()?->id;
            $type     = self::ROUTINE;

            $query = $db->createQuery()->insert($db->quoteName('#__scheduler_tasks'))
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
                $db->createQuery()->update($db->quoteName('#__scheduler_tasks'))->set($db->quoteName('state') . ' = :s')
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
            $report = (new Retention($this->db, $this->registry))->run();
            $report['core:store'] = Store::purgeExpired();

            return json_encode($report, JSON_UNESCAPED_SLASHES) ?: '';
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

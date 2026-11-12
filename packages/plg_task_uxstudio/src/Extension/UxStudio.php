<?php

namespace Ux1\Plugin\Task\UxStudio\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Task plugin: one routine "uxstudio.run" with the parameter "job" (<module>.<job>).
 * Modules declare their jobs in tasks(); the core creates the scheduler rows (Core\Scheduler).
 */
final class UxStudio extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /** @var string[][] */
    protected const TASKS_MAP = [
        'uxstudio.run' => [
            'langConstPrefix' => 'PLG_TASK_UXSTUDIO_TASK_RUN',
            'form'            => 'run',
            'method'          => 'runJob',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function runJob(ExecuteTaskEvent $event): int
    {
        if (!class_exists(Kernel::class)) {
            return TaskStatus::NO_RUN;
        }

        $params = $event->getArgument('params');
        $job    = (string) ($params->job ?? '');

        try {
            $kernel = Kernel::instance();
            $kernel->boot($this->getApplication());
            $output = $kernel->scheduler()->run($job);
        } catch (\Throwable $e) {
            $this->logTask('UX1 Studio job "' . $job . '" failed: ' . $e->getMessage(), 'error');
            $this->snapshot['output'] = $e->getMessage();

            return TaskStatus::KNOCKOUT;
        }

        $this->logTask('UX1 Studio job "' . $job . '" finished.');
        $this->snapshot['output'] = $output;

        return TaskStatus::OK;
    }
}

<?php

namespace Ux1\UxStudio\Core;

\defined('_JEXEC') or die;

/**
 * Base class of every UX Studio module (src/Modules/<Name>/Module.php).
 * Only boot() is mandatory; everything else has a harmless default.
 */
abstract class BaseModule
{
    /** @var array<string,mixed> meta.json contents plus 'dir' and 'path'. */
    private array $meta = [];

    /** Called by the registry right after construction. */
    final public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    final public function meta(): array
    {
        return $this->meta;
    }

    final public function id(): string
    {
        return (string) ($this->meta['id'] ?? '');
    }

    /** Directory of the module (contains meta.json and language/). */
    final public function path(): string
    {
        return (string) ($this->meta['path'] ?? '');
    }

    /**
     * Register listeners for Joomla events. Runs on every request (site, admin, cli) while the module is enabled.
     * Keep it cheap: register listeners, do not do work here.
     */
    abstract public function boot(ModuleContext $ctx): void;

    /**
     * Settings fields. Each: key, type (toggle|text|textarea|css|number|select|multiselect|color|media|datetime|richtext|password),
     * label, help?, default?, options? (value => label), section?, group?, group_help?, show_if?, wide?, min?, max?, step?, maxlength?.
     * Labels may be language keys (UXS_<ID>_...), the core translates them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function settingsSchema(): array
    {
        return [];
    }

    /**
     * API routes: ['GET logs' => [$this, 'listLogs'], 'POST items/{id}' => [$this, 'save']].
     * A handler receives an ApiRequest and returns an array (the JSON "data").
     *
     * @return array<string,callable>
     */
    public function routes(): array
    {
        return [];
    }

    /**
     * Schema migrations: version (int) => SQL statements ('#__' prefix). Applied in version order, once.
     *
     * @return array<int,string[]>
     */
    public function migrations(): array
    {
        return [];
    }

    /**
     * Scheduler jobs: id => ['label' => string (or language key), 'callable' => callable, 'interval' => 'PT24H'|hours].
     *
     * @return array<string,array<string|int,mixed>>
     */
    public function tasks(): array
    {
        return [];
    }

    /**
     * Log retention: [['table' => '#__uxstudio_x', 'column' => 'created', 'days' => 90]].
     *
     * @return array<int,array<string,mixed>>
     */
    public function retention(): array
    {
        return [];
    }

    /** Side effects of turning the module on (htaccess blocks etc.). Data is never touched. */
    public function onEnable(): void
    {
    }

    /** Side effects of turning the module off. Data is NOT deleted. */
    public function onDisable(): void
    {
    }

    /** Joomla ACL action required for this module's API routes and settings. */
    public function acl(): string
    {
        return 'core.manage';
    }

    /**
     * Optional card on the UX Studio overview: ['title' => string, 'value' => string|int, 'text' => string, 'status' => 'ok|warn|bad'].
     *
     * @return array<string,mixed>|null
     */
    public function dashboardWidget(): ?array
    {
        return null;
    }
}

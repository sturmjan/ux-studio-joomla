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

    private ?ModuleContext $ctx = null;

    /** Called by the kernel before boot(). */
    final public function setContext(ModuleContext $ctx): void
    {
        $this->ctx = $ctx;
    }

    /**
     * The module's context for route handlers, jobs and listeners. Available even on requests where boot() did not
     * run (API, scheduler, CLI): it is created lazily. Use this instead of keeping your own copy.
     */
    final protected function ctx(): ModuleContext
    {
        if ($this->ctx === null) {
            $kernel    = Kernel::instance();
            $this->ctx = new ModuleContext($this->id(), $kernel->app(), $kernel);
        }

        return $this->ctx;
    }

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
     * A route may require a stricter ACL action than acl(): 'POST purge' => ['handler' => [$this, 'purge'], 'acl' => 'core.admin'].
     *
     * @return array<string,callable|array{handler:callable,acl?:string}>
     */
    public function routes(): array
    {
        return [];
    }

    /**
     * Public (site) endpoint routes for visitors and external systems (forms, consent, webhooks, Central App):
     *   index.php?option=com_uxstudio&task=public.dispatch&format=json&route=<module-id>/<path>
     * Format: ['POST subscribe' => ['handler' => [$this, 'subscribe'], 'auth' => 'none', 'limit' => [10, 60], 'max_body' => 16384]].
     *   - auth: 'none' (default; browsers only: a present Origin header must be this site) | 'token' (Authorization: Bearer <secret>)
     *     | 'hmac' (X-UXS-Timestamp + X-UXS-Signature = hex hmac_sha256("<timestamp>.<raw body>", secret), 5 minute window).
     *   - secret: string or callable returning the secret (token / hmac; an empty secret denies every request).
     *   - limit: [max requests, window in seconds] per client IP and route (default [30, 60]); exceeding it returns 429.
     *   - max_body: maximum request body in bytes (default 65536), larger returns 413 (for multipart this is the whole request, uploads included).
     *   - max_files / max_file_size: accept multipart/form-data uploads (ApiRequest::files()): at most max_files files (default 0 = uploads
     *     are ignored), each at most max_file_size bytes (default 5 MB); more or larger returns 413. The text parts of a multipart request
     *     are in ApiRequest::body() as raw strings, nested names like fields[email] arrive as arrays. Not for hmac routes (nothing to sign).
     * No Joomla session, ACL or CSRF token is involved, so a handler must validate everything itself.
     *
     * @return array<string,array<string,mixed>>
     */
    public function publicRoutes(): array
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

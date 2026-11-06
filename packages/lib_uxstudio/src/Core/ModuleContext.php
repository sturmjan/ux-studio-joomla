<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Priority;

\defined('_JEXEC') or die;

/**
 * What a module gets in boot(): the application, event registration and handy accessors.
 */
final class ModuleContext
{
    public function __construct(
        private string $moduleId,
        private CMSApplicationInterface $app,
        private Kernel $kernel
    ) {
    }

    public function id(): string
    {
        return $this->moduleId;
    }

    public function app(): CMSApplicationInterface
    {
        return $this->app;
    }

    public function db(): DatabaseInterface
    {
        return $this->kernel->db();
    }

    public function kernel(): Kernel
    {
        return $this->kernel;
    }

    /** 'site' | 'administrator' | 'api' | 'cli' ... as reported by the application. */
    public function client(): string
    {
        return $this->app->getName();
    }

    public function isAdmin(): bool
    {
        return $this->app->isClient('administrator');
    }

    public function isSite(): bool
    {
        return $this->app->isClient('site');
    }

    /**
     * Listen to a Joomla event, e.g. $ctx->on('onAfterRender', [$this, 'inject'], Priority::LOW).
     * onAfterInitialise has already been dispatched when modules boot, listen to later events.
     */
    public function on(string $event, callable $listener, int $priority = Priority::NORMAL): void
    {
        $this->app->getDispatcher()->addListener($event, $listener, $priority);
    }

    /** One setting of this module (decrypted for password fields, default from the schema when unset). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->kernel->settings()->get($this->moduleId, $key, $default);
    }

    /** All settings of this module (decrypted). */
    public function settings(): array
    {
        return $this->kernel->settings()->all($this->moduleId);
    }

    public function t(string $key, mixed ...$args): string
    {
        return $args ? Text::sprintf($key, ...$args) : Text::_($key);
    }

    public function http(): Http
    {
        return new Http();
    }
}

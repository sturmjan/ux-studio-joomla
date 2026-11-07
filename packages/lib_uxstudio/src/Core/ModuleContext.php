<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\WebAssetManager;
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

    /** Key / value storage of this module (Core\Store). */
    public function store(): Store
    {
        return new Store($this->kernel->db(), $this->moduleId);
    }

    /**
     * Shows a notice on the Joomla control panel and the UX Studio screen of the administration until clearNotice() is called
     * (see Core\Notices). $link is an administrator URL ("index.php?option=...") or ''.
     *
     * @param string $level info | warning | error
     */
    public function notice(string $key, string $message, string $level = 'warning', string $link = ''): void
    {
        Notices::raise($this->moduleId, $key, $message, $level, $link);
    }

    public function clearNotice(string $key): void
    {
        Notices::clear($this->moduleId, $key);
    }

    /**
     * Asks the Joomla scheduler to run a job of this module (declared in tasks()) soon, once, in addition to its regular
     * interval: for example the first scan right after the module was switched on. It runs at the next scheduler tick (lazy
     * scheduler in the browser, cron or scheduler:run), not inside this request.
     *
     * @param int $delaySeconds wait at least this long (default: as soon as possible)
     * @return bool false when the module has no such job or the scheduler is not available
     */
    public function runJobSoon(string $jobId, int $delaySeconds = 0): bool
    {
        return $this->kernel->scheduler()->runSoon($this->moduleId, $jobId, $delaySeconds);
    }

    /**
     * Front-end assets of the module live in packages/lib_uxstudio/media/modules/<module-id>/ and are published to
     * media/lib_uxstudio/modules/<module-id>/. This returns the site-relative URL of one file (path inside that folder).
     */
    public function assetUrl(string $file): string
    {
        $this->assertAssetName($file);

        return Uri::root(true) . '/media/lib_uxstudio/modules/' . $this->moduleId . '/' . $file;
    }

    /**
     * Registers and enqueues a script of the module through the WebAssetManager (CSP friendly: no inline code, the
     * nonce is handled by Joomla). Does nothing for non-HTML documents.
     *
     * @param array<string,mixed> $attributes script attributes, e.g. ['type' => 'module'] (default ['defer' => true])
     * @param string[]            $dependencies names of other registered assets
     */
    public function useScript(string $file, array $attributes = [], array $dependencies = []): void
    {
        $wa = $this->assets();
        if ($wa === null) {
            return;
        }
        $wa->registerAndUseScript($this->assetName($file), $this->assetPath($file), ['version' => $this->assetVersion($file)], $attributes ?: ['defer' => true], $dependencies);
    }

    /** Registers and enqueues a stylesheet of the module (see useScript()). */
    public function useStyle(string $file, array $attributes = [], array $dependencies = []): void
    {
        $wa = $this->assets();
        if ($wa === null) {
            return;
        }
        $wa->registerAndUseStyle($this->assetName($file), $this->assetPath($file), ['version' => $this->assetVersion($file)], $attributes, $dependencies);
    }

    /** Root-relative path: WebAssetManager resolves a file below the site root as given (it must exist, else it is skipped). */
    private function assetPath(string $file): string
    {
        $this->assertAssetName($file);

        return 'media/lib_uxstudio/modules/' . $this->moduleId . '/' . $file;
    }

    private function assets(): ?WebAssetManager
    {
        $document = $this->app->getDocument();

        return $document instanceof HtmlDocument ? $document->getWebAssetManager() : null;
    }

    private function assetName(string $file): string
    {
        return 'uxs.' . $this->moduleId . '.' . $file;
    }

    private function assetVersion(string $file): string
    {
        $path = JPATH_ROOT . '/media/lib_uxstudio/modules/' . $this->moduleId . '/' . $file;
        $seed = is_file($path) ? (int) filemtime($path) . ':' . (int) filesize($path) : Kernel::version();

        return substr(md5($seed), 0, 10);
    }

    private function assertAssetName(string $file): void
    {
        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*\.(js|mjs|css|svg|png|jpg|webp|woff2)$#', $file) || str_contains($file, '..')) {
            throw new \InvalidArgumentException('Invalid asset file name.');
        }
    }
}

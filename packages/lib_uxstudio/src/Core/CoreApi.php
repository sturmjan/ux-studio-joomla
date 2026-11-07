<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;

\defined('_JEXEC') or die;

/**
 * Routes served by the core itself under the "core/" prefix: meta, modules (list + toggle), settings, dashboard, migrate.
 */
final class CoreApi
{
    public function __construct(private Kernel $kernel)
    {
    }

    /** @return array<string,callable> */
    public function routes(): array
    {
        return [
            'GET meta'            => [$this, 'meta'],
            'GET modules'         => [$this, 'modules'],
            'POST modules/{id}'   => [$this, 'toggle'],
            'GET settings/{id}'   => [$this, 'getSettings'],
            'POST settings/{id}'  => [$this, 'saveSettings'],
            'GET dashboard'       => [$this, 'dashboard'],
            'POST migrate'        => [$this, 'migrate'],
        ];
    }

    public function meta(ApiRequest $r): array
    {
        $app = $this->kernel->app();

        return [
            'version' => Kernel::version(),
            'joomla'  => (new Version())->getShortVersion(),
            'php'     => PHP_VERSION,
            'locale'  => $app->getLanguage()->getTag(),
            'modules' => \count($this->kernel->registry()->all()),
            'canAdmin' => $r->user()->authorise('core.admin', Api::COMPONENT),
        ];
    }

    /** Translated module descriptor for the SPA. */
    private function describe(string $id): array
    {
        $registry = $this->kernel->registry();
        $meta     = $registry->meta($id);
        $module   = $registry->module($id);
        if ($module) {
            Lang::loadModule($this->kernel->app(), $id, $module->path());
        }
        $key  = 'UXS_' . strtoupper(str_replace('-', '_', $id));
        $lang = $this->kernel->app()->getLanguage();
        $tr   = static fn (string $suffix, string $fallback): string => $lang->hasKey($key . $suffix) ? Text::_($key . $suffix) : $fallback;

        return [
            'id'          => $id,
            'name'        => $tr('_NAME', (string) $meta['name']),
            'description' => $tr('_DESC', (string) $meta['description']),
            'group'       => (string) $meta['group'],
            'icon'        => (string) $meta['icon'],
            'settings'    => (bool) $meta['settings'],
            'enabled'     => $registry->isEnabled($id),
            'requires'    => array_values((array) $meta['requires']),
            'error'       => $registry->error($id),
        ];
    }

    public function modules(ApiRequest $r): array
    {
        $out = [];
        foreach (array_keys($this->kernel->registry()->all()) as $id) {
            $out[] = $this->describe($id);
        }

        return $out;
    }

    public function toggle(ApiRequest $r): array
    {
        $id       = $r->param('id');
        $registry = $this->kernel->registry();
        $module   = $registry->has($id) ? $registry->module($id) : null;
        if (!$module) {
            throw new ApiException(Text::_('UXS_CORE_ERR_MODULE_UNKNOWN'), 404, 'unknown_module');
        }
        if (!$r->user()->authorise($module->acl(), Api::COMPONENT) || !$this->sideEffectsAllowed($r, $id)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
        if (!\array_key_exists('enabled', $r->body())) {
            throw new ApiException('Missing "enabled".', 422, 'invalid_value');
        }

        $enable  = $r->bool('enabled');
        $applied = [];

        if ($enable && !$registry->isEnabled($id)) {
            $missing = array_values(array_filter((array) $registry->meta($id)['requires'], fn ($dep) => !$registry->isEnabled((string) $dep)));
            if ($missing) {
                throw new ApiException(Text::sprintf('UXS_CORE_ERR_REQUIRES', implode(', ', $missing)), 409, 'requires');
            }
            $applied = $this->kernel->migrator()->run($id);
            $registry->setEnabled($id, true);
            Lang::loadModule($this->kernel->app(), $id, $module->path());
            // Scheduler tasks first: onEnable() may ask for a first run (runJobSoon), which only touches a task that is on.
            $this->kernel->scheduler()->enableModule($id);
            $module->onEnable();
        } elseif (!$enable && $registry->isEnabled($id)) {
            $by = $registry->dependents($id);
            if ($by) {
                throw new ApiException(Text::sprintf('UXS_CORE_ERR_REQUIRED_BY', implode(', ', $by)), 409, 'required_by');
            }
            $registry->setEnabled($id, false);
            Lang::loadModule($this->kernel->app(), $id, $module->path());
            $module->onDisable();
            $this->kernel->scheduler()->disableModule($id);
        }

        return $this->describe($id) + ['migrations' => $applied];
    }

    /**
     * Modules whose enabling, disabling or settings write files or server configuration (.htaccess) declare "toggle_acl"
     * in meta.json; that stricter ACL then also guards the toggle and the settings, so they cannot bypass admin-only routes.
     */
    private function sideEffectsAllowed(ApiRequest $r, string $id): bool
    {
        $acl = (string) ($this->kernel->registry()->meta($id)['toggle_acl'] ?? '');

        return $acl === '' || $r->user()->authorise($acl, Api::COMPONENT);
    }

    /** The core's own settings (id "core") are an administrator matter. */
    private function coreSettingsGuard(ApiRequest $r): void
    {
        if (!$r->user()->authorise('core.admin', Api::COMPONENT)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
    }

    private function moduleForSettings(ApiRequest $r): ?BaseModule
    {
        $id       = $r->param('id');
        if ($id === CoreSettings::ID) {
            $this->coreSettingsGuard($r);

            return null;
        }
        $registry = $this->kernel->registry();
        $module   = $registry->has($id) ? $registry->module($id) : null;
        if (!$module) {
            throw new ApiException(Text::_('UXS_CORE_ERR_MODULE_UNKNOWN'), 404, 'unknown_module');
        }
        if (!$r->user()->authorise($module->acl(), Api::COMPONENT) || !$this->sideEffectsAllowed($r, $id)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
        Lang::loadModule($this->kernel->app(), $id, $module->path());

        return $module;
    }

    public function getSettings(ApiRequest $r): array
    {
        $id = $this->moduleForSettings($r)?->id() ?? CoreSettings::ID;
        $s  = $this->kernel->settings();

        return ['id' => $id, 'schema' => $s->schema($id), 'values' => $s->forClient($id)];
    }

    public function saveSettings(ApiRequest $r): array
    {
        $id     = $this->moduleForSettings($r)?->id() ?? CoreSettings::ID;
        $s      = $this->kernel->settings();
        $values = $s->save($id, $r->body());
        if ($id === CoreSettings::ID) {
            ClientIp::reset();
        }

        return ['id' => $id, 'schema' => $s->schema($id), 'values' => $values];
    }

    public function dashboard(ApiRequest $r): array
    {
        $widgets = [];
        foreach ($this->kernel->registry()->enabledIds() as $id) {
            $module = $this->kernel->registry()->module($id);
            if (!$module || !$r->user()->authorise($module->acl(), Api::COMPONENT)) {
                continue;
            }
            Lang::loadModule($this->kernel->app(), $id, $module->path());
            try {
                $widget = $module->dashboardWidget();
            } catch (\Throwable $e) {
                $widget = null;
            }
            if ($widget) {
                $widgets[] = ['module' => $id] + $widget;
            }
        }

        return $widgets;
    }

    /** Run pending migrations of all enabled modules and re-sync scheduler tasks (useful after dev-link of new code). */
    public function migrate(ApiRequest $r): array
    {
        if (!$r->user()->authorise('core.admin', Api::COMPONENT)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }

        $applied = $this->kernel->migrator()->runAll();
        $this->kernel->scheduler()->syncAll();

        return $applied;
    }
}

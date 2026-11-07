<?php

namespace Ux1\UxStudio\Core\Onboarding;

use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\Api;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\CoreApi;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Routes of the setup wizard, served under "core/onboarding" (merged into the core routes by Api):
 *   GET  core/onboarding           state + everything the wizard needs (site types, modules from the registry, defaults)
 *   POST core/onboarding/state     {action: start|dismiss}
 *   POST core/onboarding/profile   {sender_email, sender_name, contact_email} (filled into the settings of modules that have them)
 *   POST core/onboarding/complete  {site_type, modules: [ids the wizard turned on]}
 * Modules themselves are turned on by the SPA through the existing core/modules/<id> route, one request per module.
 */
final class OnboardingApi
{
    public function __construct(private Kernel $kernel)
    {
    }

    /** @return array<string,callable> */
    public function routes(): array
    {
        return [
            'GET onboarding'           => [$this, 'show'],
            'POST onboarding/state'    => [$this, 'state'],
            'POST onboarding/profile'  => [$this, 'profile'],
            'POST onboarding/complete' => [$this, 'complete'],
        ];
    }

    private function store(): OnboardingStore
    {
        return new OnboardingStore($this->kernel->db());
    }

    /** Ids of regular (non-development) modules. */
    private function regularIds(): array
    {
        $out = [];
        foreach ($this->kernel->registry()->all() as $id => $meta) {
            if (empty($meta['dev']) && $id !== OnboardingCatalog::CA_MODULE) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    /** Ids of regular modules that are on right now. */
    private function enabledRegular(): array
    {
        $registry = $this->kernel->registry();

        $ids = $this->regularIds();
        if ($registry->has(OnboardingCatalog::CA_MODULE)) {
            $ids[] = OnboardingCatalog::CA_MODULE;
        }

        return array_values(array_filter($ids, static fn (string $id): bool => $registry->isEnabled($id)));
    }

    public function show(ApiRequest $r): array
    {
        $registry = $this->kernel->registry();
        $state    = $this->store()->load();
        $regular  = array_flip($this->regularIds());

        $described      = (new CoreApi($this->kernel))->modules($r);
        $enabledRegular = 0;
        $modules        = [];
        foreach ($described as $m) {
            if (!isset($regular[$m['id']])) {
                continue;
            }
            $enabledRegular += $m['enabled'] ? 1 : 0;
            $modules[] = $m + ['types' => OnboardingCatalog::typesFor($m['id'])];
        }

        $types = [];
        foreach (OnboardingCatalog::siteTypes() as $type) {
            $key     = 'UXS_CORE_WIZ_TYPE_' . strtoupper($type['id']);
            $types[] = $type + ['label' => Text::_($key), 'description' => Text::_($key . '_DESC')];
        }

        $targets = [];
        foreach (OnboardingCatalog::profileTargets() as $field => $list) {
            $targets[$field] = [];
            foreach ($list as [$moduleId, $key]) {
                if ($registry->has($moduleId) && isset($this->kernel->settings()->rawSchema($moduleId)[$key])) {
                    $targets[$field][] = $moduleId;
                }
            }
        }

        $app     = $this->kernel->app();
        $profile = $state['profile'];
        $defaults = [
            'sender_email'  => (string) $app->get('mailfrom', ''),
            'sender_name'   => (string) $app->get('fromname', ''),
            'contact_email' => (string) $r->user()->email,
        ];

        $ca = null;
        if ($registry->has(OnboardingCatalog::CA_MODULE)) {
            $caModules = array_values(array_filter($described, static fn (array $m): bool => $m['id'] === OnboardingCatalog::CA_MODULE));
            $ca        = $caModules[0] ?? null;
        }

        $pending = \in_array($state['status'], ['new', 'started'], true) && $enabledRegular === 0;

        return [
            'state'           => $state,
            'pending'         => $pending,
            'auto'            => $pending && $state['status'] === 'new',
            'site_types'      => $types,
            'modules'         => $modules,
            'profile'         => $profile,
            'defaults'        => $defaults,
            'profile_targets' => $targets,
            'site'            => ['name' => (string) $app->get('sitename', ''), 'url' => \Joomla\CMS\Uri\Uri::root()],
            'ca'              => $ca,
        ];
    }

    public function state(ApiRequest $r): array
    {
        $action = $r->string('action');
        $now    = \Joomla\CMS\Factory::getDate()->toSql();
        $store  = $this->store();

        return match ($action) {
            'start'   => ['state' => $store->save(['status' => 'started', 'started_at' => $now, 'baseline' => $this->enabledRegular()])],
            'dismiss' => ['state' => $store->save(['status' => $store->load()['status'] === 'completed' ? 'completed' : 'dismissed', 'dismissed_at' => $now])],
            default   => throw new ApiException('Unknown action.', 422, 'invalid_value'),
        };
    }

    public function profile(ApiRequest $r): array
    {
        $in = [
            'sender_email'  => trim($r->string('sender_email')),
            'sender_name'   => trim(strip_tags($r->string('sender_name'))),
            'contact_email' => trim($r->string('contact_email')),
        ];
        foreach (['sender_email', 'contact_email'] as $field) {
            if ($in[$field] !== '' && (\strlen($in[$field]) > 190 || !filter_var($in[$field], FILTER_VALIDATE_EMAIL))) {
                throw new ApiException(Text::_('UXS_CORE_WIZ_ERR_EMAIL'), 422, 'invalid_value');
            }
        }
        if (mb_strlen($in['sender_name']) > 120) {
            $in['sender_name'] = mb_substr($in['sender_name'], 0, 120);
        }

        $store    = $this->store();
        $previous = $store->load()['profile'];
        $applied  = $this->apply($r, $in, $previous);
        // A field left empty keeps the value remembered from an earlier run (it marks what the wizard wrote itself).
        $merged = array_replace($previous, array_filter($in, static fn (string $v): bool => $v !== ''));
        $state  = $store->save(['profile' => $merged]);

        return ['state' => $state, 'applied' => $applied];
    }

    /**
     * Fills the profile into module settings, never overwriting a value the administrator set on his own.
     *
     * @return array<int,array{module:string,key:string}>
     */
    private function apply(ApiRequest $r, array $profile, array $previous): array
    {
        $registry = $this->kernel->registry();
        $settings = $this->kernel->settings();
        $applied  = [];

        foreach (OnboardingCatalog::profileTargets() as $field => $targets) {
            $value = (string) ($profile[$field] ?? '');
            if ($value === '') {
                continue;
            }
            foreach ($targets as [$moduleId, $key]) {
                $module = $registry->has($moduleId) ? $registry->module($moduleId) : null;
                if (!$module || !isset($settings->rawSchema($moduleId)[$key]) || !$r->user()->authorise($module->acl(), Api::COMPONENT)) {
                    continue;
                }
                $current = trim((string) $settings->get($moduleId, $key, ''));
                if ($current === $value || ($current !== '' && $current !== (string) ($previous[$field] ?? ''))) {
                    continue;
                }
                try {
                    $settings->save($moduleId, [$key => $value]);
                    $applied[] = ['module' => $moduleId, 'key' => $key];
                } catch (ApiException $e) {
                    // A value the module refuses is left for the administrator to set by hand.
                }
            }
        }

        return $applied;
    }

    public function complete(ApiRequest $r): array
    {
        $type = $r->string('site_type');
        if (!\in_array($type, OnboardingCatalog::typeIds(), true)) {
            throw new ApiException(Text::_('UXS_CORE_WIZ_ERR_TYPE'), 422, 'invalid_value');
        }
        $registry = $this->kernel->registry();
        $modules  = [];
        $baseline = $this->store()->load()['baseline'];
        foreach ((array) ($r->body()['modules'] ?? []) as $id) {
            // Only what the wizard itself turned on: not a module that was already on when it was opened.
            if (\is_string($id) && $registry->has($id) && $registry->isEnabled($id) && !\in_array($id, $baseline ?? [], true)) {
                $modules[] = $id;
            }
        }

        $state = $this->store()->save([
            'status'       => 'completed',
            'site_type'    => $type,
            'modules'      => array_values(array_unique($modules)),
            'completed_at' => \Joomla\CMS\Factory::getDate()->toSql(),
        ]);

        return ['state' => $state];
    }
}

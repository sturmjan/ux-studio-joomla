<?php

namespace Ux1\UxStudio\Modules\DashboardWidgets;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Ux1\UxStudio\Core\Api;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Lang;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Cards on the Joomla administration home screen (com_cpanel): summaries that UX Studio modules supply through
 * dashboardWidget(), plus own notes and links. Every card can be hidden or limited to user groups. The cards are drawn by
 * the administrator module mod_uxstudio_dashboard (position "cpanel"), which this module publishes when it is turned on.
 */
final class Module extends BaseModule
{
    public const JOOMLA_MODULE = 'mod_uxstudio_dashboard';

    /** Rules of other Joomla administrator modules are stored under this prefix + the module element (joomla.mod_popular). */
    private const JOOMLA_PREFIX = 'joomla.';

    public function boot(ModuleContext $ctx): void
    {
        // Our own cards are asked for by the administrator module. Blocks of OTHER modules on the home screen are filtered here.
        if ($ctx->isAdmin()) {
            $ctx->on('onAfterModuleList', [$this, 'filterHomeScreenModules']);
        }
    }

    public function acl(): string
    {
        return 'core.manage';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'show_in_cpanel', 'type' => 'toggle', 'label' => 'UXS_DASHBOARD_WIDGETS_SET_CPANEL', 'help' => 'UXS_DASHBOARD_WIDGETS_SET_CPANEL_HELP', 'default' => true],
            ['key' => 'show_module_cards', 'type' => 'toggle', 'label' => 'UXS_DASHBOARD_WIDGETS_SET_MODULE_CARDS', 'help' => 'UXS_DASHBOARD_WIDGETS_SET_MODULE_CARDS_HELP', 'default' => true],
            ['key' => 'show_items', 'type' => 'toggle', 'label' => 'UXS_DASHBOARD_WIDGETS_SET_ITEMS', 'help' => 'UXS_DASHBOARD_WIDGETS_SET_ITEMS_HELP', 'default' => true],
        ];
    }

    public function migrations(): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_dashboard_items` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `kind` VARCHAR(10) NOT NULL DEFAULT \'note\', `title` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`body` TEXT NULL, `url` VARCHAR(500) NOT NULL DEFAULT \'\', `group_ids` VARCHAR(500) NOT NULL DEFAULT \'[]\', '
                . '`published` TINYINT NOT NULL DEFAULT 1, `ordering` INT NOT NULL DEFAULT 0, `created` DATETIME NOT NULL, `created_by` INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (`id`), KEY `idx_published` (`published`, `ordering`)) ' . $opts,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_dashboard_rules` ('
                . '`module` VARCHAR(64) NOT NULL, `hidden` TINYINT NOT NULL DEFAULT 0, `group_ids` VARCHAR(500) NOT NULL DEFAULT \'[]\', '
                . 'PRIMARY KEY (`module`)) ' . $opts,
            ],
        ];
    }

    public function onEnable(): void
    {
        try {
            $this->publishJoomlaModule(true);
        } catch (\Throwable $e) {
            Log::add('dashboard-widgets: publishing the home screen module failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    public function onDisable(): void
    {
        try {
            $this->publishJoomlaModule(false);
        } catch (\Throwable $e) {
            Log::add('dashboard-widgets: unpublishing the home screen module failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    public function routes(): array
    {
        return [
            'GET overview'            => [$this, 'overview'],
            'GET preview'             => [$this, 'preview'],
            'POST rules/{module}'     => ['handler' => [$this, 'saveRule'], 'acl' => 'core.admin'],
            'POST items'              => [$this, 'createItem'],
            'POST items/{id}'         => [$this, 'updateItem'],
            'POST items/{id}/delete'  => ['handler' => [$this, 'deleteItem'], 'acl' => 'core.admin'],
            'POST publish'            => ['handler' => [$this, 'publish'], 'acl' => 'core.admin'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $items = $this->items()->all(true);
        } catch (\Throwable $e) {
            return null;
        }
        $notes = \count(array_filter($items, static fn (array $i): bool => $i['kind'] === 'note'));

        return [
            'title'  => Text::_('UXS_DASHBOARD_WIDGETS_NAME'),
            'value'  => \count($items),
            'text'   => Text::sprintf('UXS_DASHBOARD_WIDGETS_WIDGET', $notes, \count($items) - $notes),
            'status' => 'ok',
        ];
    }

    /* ---------------------------------------------------------------- rendering (home screen module) */

    /** Loads the stylesheet of the cards on the current page. */
    public function useAssets(): void
    {
        $this->ctx()->useStyle('cards.css');
    }

    public function showInCpanel(): bool
    {
        return (bool) $this->ctx()->setting('show_in_cpanel', true);
    }

    /**
     * The cards this user sees on the home screen, in display order.
     *
     * @return array<int,array{kind:string,title:string,value:string,text:string,status:string,url:string,body:string}>
     */
    public function cardsFor(User $user): array
    {
        $userGroups = array_map('intval', $user->getAuthorisedGroups());
        $cards      = [];

        if ((bool) $this->ctx()->setting('show_module_cards', true)) {
            $rules = $this->items()->rules();
            foreach ($this->moduleCards($user) as $c) {
                $rule = $rules[$c['module']] ?? ['hidden' => false, 'groups' => []];
                if ($rule['hidden'] || !self::visibleTo($rule['groups'], $userGroups)) {
                    continue;
                }
                $cards[] = [
                    'kind'   => 'module',
                    'title'  => $c['title'],
                    'value'  => $c['value'],
                    'text'   => $c['text'],
                    'status' => $c['status'],
                    'url'    => 'index.php?option=com_uxstudio#/module?id=' . rawurlencode($c['module']),
                    'body'   => '',
                ];
            }
        }
        if ((bool) $this->ctx()->setting('show_items', true)) {
            foreach ($this->items()->all(true) as $i) {
                if (!self::visibleTo($i['groups'], $userGroups)) {
                    continue;
                }
                $cards[] = [
                    'kind'   => $i['kind'],
                    'title'  => $i['title'],
                    'value'  => '',
                    'text'   => '',
                    'status' => '',
                    'url'    => $i['kind'] === 'link' ? $i['url'] : '',
                    'body'   => $i['kind'] === 'note' ? $i['body'] : '',
                ];
            }
        }

        return $cards;
    }

    /**
     * Event onAfterModuleList: drops the blocks (modules in positions cpanel* / icon) that a rule hides from, or limits to
     * groups the current user is not in. Only on the Joomla home screen; our own block is never filtered.
     */
    public function filterHomeScreenModules(object $event): void
    {
        try {
            $app = $this->ctx()->app();
            if ($app->getInput()->getCmd('option') !== 'com_cpanel') {
                return;
            }
            $modules = $event->getArgument('modules');
            if (!\is_array($modules) || !$modules) {
                return;
            }
            $rules = [];
            foreach ($this->items()->rules() as $key => $rule) {
                if (str_starts_with($key, self::JOOMLA_PREFIX)) {
                    $rules[$key] = $rule;
                }
            }
            if (!$rules) {
                return;
            }
            $user       = $app->getIdentity();
            $userGroups = $user ? array_map('intval', $user->getAuthorisedGroups()) : [];
            foreach ($modules as $k => $m) {
                if (!\is_object($m) || ($m->module ?? '') === self::JOOMLA_MODULE || !self::isHomePosition((string) ($m->position ?? ''))) {
                    continue;
                }
                $rule = $rules[self::JOOMLA_PREFIX . $m->module] ?? null;
                if ($rule && ($rule['hidden'] || !self::visibleTo($rule['groups'], $userGroups))) {
                    unset($modules[$k]);
                }
            }
            $event->setArgument('modules', $modules);
        } catch (\Throwable $e) {
            Log::add('dashboard-widgets: filtering the home screen modules failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }

    private static function isHomePosition(string $position): bool
    {
        return $position === 'icon' || str_starts_with($position, 'cpanel');
    }

    /**
     * Blocks of other administrator modules that are published on the Joomla home screen, keyed by rule key.
     *
     * @return array<string,array{module:string,name:string,positions:string[]}>
     */
    private function joomlaBlocks(): array
    {
        $db   = $this->ctx()->db();
        $self = self::JOOMLA_MODULE;
        $rows = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('module'), $db->quoteName('title'), $db->quoteName('position')])->from($db->quoteName('#__modules'))
                ->where($db->quoteName('client_id') . ' = 1')->where($db->quoteName('published') . ' = 1')->where($db->quoteName('module') . ' <> :self')
                ->where('(' . $db->quoteName('position') . ' LIKE ' . $db->quote('cpanel%') . ' OR ' . $db->quoteName('position') . ' = ' . $db->quote('icon') . ')')
                ->bind(':self', $self)->order($db->quoteName('module') . ' ASC, ' . $db->quoteName('id') . ' ASC')
        )->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $key = self::JOOMLA_PREFIX . $r['module'];
            if (!preg_match('/^[a-z0-9_]+$/', (string) $r['module'])) {
                continue;
            }
            $out[$key] ??= ['module' => $key, 'name' => Text::_((string) $r['title']), 'positions' => []];
            if (!\in_array($r['position'], $out[$key]['positions'], true)) {
                $out[$key]['positions'][] = (string) $r['position'];
            }
        }

        return $out;
    }

    /** @param int[] $allowed empty = everyone @param int[] $userGroups */
    private static function visibleTo(array $allowed, array $userGroups): bool
    {
        return !$allowed || (bool) array_intersect($allowed, $userGroups);
    }

    /**
     * Summaries from the other enabled modules the user may see (module ACL), with the module id.
     *
     * @return array<int,array{module:string,name:string,title:string,value:string,text:string,status:string}>
     */
    private function moduleCards(User $user): array
    {
        $kernel   = $this->ctx()->kernel();
        $registry = $kernel->registry();
        $out      = [];
        foreach ($registry->enabledIds() as $id) {
            if ($id === $this->id()) {
                continue;
            }
            $module = $registry->module($id);
            if (!$module || !$user->authorise($module->acl(), Api::COMPONENT)) {
                continue;
            }
            Lang::loadModule($this->ctx()->app(), $id, $module->path());
            try {
                $w = $module->dashboardWidget();
            } catch (\Throwable $e) {
                $w = null;
            }
            if (!\is_array($w)) {
                continue;
            }
            $status = (string) ($w['status'] ?? '');
            $out[]  = [
                'module' => $id,
                'name'   => (string) ($registry->meta($id)['name'] ?? $id),
                'title'  => (string) ($w['title'] ?? $id),
                'value'  => isset($w['value']) && \is_scalar($w['value']) ? (string) $w['value'] : '',
                'text'   => (string) ($w['text'] ?? ''),
                'status' => \in_array($status, ['ok', 'warn', 'bad'], true) ? $status : '',
            ];
        }

        return $out;
    }

    /* ---------------------------------------------------------------- API */

    /** GET dashboard-widgets/overview: everything the screen needs. */
    public function overview(ApiRequest $r): array
    {
        $rules = $this->items()->rules();
        $cards = [];
        foreach ($this->moduleCards($r->user()) as $c) {
            $rule    = $rules[$c['module']] ?? ['hidden' => false, 'groups' => []];
            $cards[] = $c + ['hidden' => $rule['hidden'], 'groups' => $rule['groups']];
        }

        $joomla = [];
        foreach ($this->joomlaBlocks() as $key => $b) {
            $rule     = $rules[$key] ?? ['hidden' => false, 'groups' => []];
            $joomla[] = ['module' => $key, 'name' => $b['name'], 'title' => $b['name'], 'value' => '', 'text' => implode(', ', $b['positions']), 'status' => '']
                + ['hidden' => $rule['hidden'], 'groups' => $rule['groups']];
        }

        return [
            'module_cards' => $cards,
            'joomla_cards' => $joomla,
            'items'        => $this->items()->all(),
            'groups'       => $this->items()->userGroups(),
            'cpanel'       => $this->joomlaModuleState(),
        ];
    }

    /** GET dashboard-widgets/preview: the cards as the current user would see them on the home screen. */
    public function preview(ApiRequest $r): array
    {
        return ['cards' => $this->cardsFor($r->user())];
    }

    /** POST dashboard-widgets/rules/{module} {hidden, groups[]} */
    public function saveRule(ApiRequest $r): array
    {
        $id = $r->param('module');
        $isJoomla = (bool) preg_match('/^joomla\.[a-z0-9_]+$/', $id);
        $known    = $isJoomla
            ? isset($this->joomlaBlocks()[$id])
            : (preg_match('/^[a-z][a-z0-9-]*$/', $id) && $this->ctx()->kernel()->registry()->has($id) && $id !== $this->id());
        if (!$known) {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_MODULE'), 404, 'unknown_module');
        }
        $groups = $this->items()->existingGroups($this->intList($r->input('groups', [])));
        $this->items()->saveRule($id, $r->bool('hidden'), $groups);

        return ['module' => $id, 'hidden' => $r->bool('hidden'), 'groups' => $groups];
    }

    /** POST dashboard-widgets/items {kind,title,body,url,groups[],published,ordering} */
    public function createItem(ApiRequest $r): array
    {
        $id = $this->items()->save(null, $this->validItem($r), (int) $r->user()->id);

        return (array) $this->items()->get($id);
    }

    /** POST dashboard-widgets/items/{id} */
    public function updateItem(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if ($this->items()->get($id) === null) {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $this->items()->save($id, $this->validItem($r), (int) $r->user()->id);

        return (array) $this->items()->get($id);
    }

    /** POST dashboard-widgets/items/{id}/delete */
    public function deleteItem(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if (!$this->items()->delete($id)) {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return ['id' => $id];
    }

    /** POST dashboard-widgets/publish: (re)publishes the home screen module in Joomla. */
    public function publish(ApiRequest $r): array
    {
        if (!$this->publishJoomlaModule(true)) {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_NOT_INSTALLED'), 409, 'module_not_installed');
        }

        return $this->joomlaModuleState();
    }

    /* ---------------------------------------------------------------- internals */

    private function items(): Items
    {
        return new Items($this->ctx()->db());
    }

    /** @return array<string,mixed> validated item fields */
    private function validItem(ApiRequest $r): array
    {
        $kind  = $r->string('kind');
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags($r->string('title'))) ?? '');
        if (!\in_array($kind, ['note', 'link'], true)) {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_KIND'), 422, 'invalid_kind');
        }
        if ($title === '') {
            throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_TITLE'), 422, 'invalid_title');
        }
        $url = '';
        if ($kind === 'link') {
            $url = trim($r->string('url'));
            if (!self::isSafeUrl($url)) {
                throw new ApiException(Text::_('UXS_DASHBOARD_WIDGETS_ERR_URL'), 422, 'invalid_url');
            }
        }
        $body = $kind === 'note' ? trim(str_replace("\0", '', strip_tags($r->string('body')))) : '';

        return [
            'kind'      => $kind,
            'title'     => mb_substr($title, 0, 190),
            'body'      => mb_substr($body, 0, 5000),
            'url'       => $url,
            'groups'    => $this->items()->existingGroups($this->intList($r->input('groups', []))),
            'published' => $r->bool('published', true),
            'ordering'  => max(-9999, min(9999, $r->int('ordering'))),
        ];
    }

    /** http(s) links, absolute paths and administrator-relative "index.php?..." only (no javascript:, data: ...). */
    private static function isSafeUrl(string $url): bool
    {
        if ($url === '' || \strlen($url) > 500 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $url)) {
            return false;
        }
        if (preg_match('#^https?://[^/]+#i', $url)) {
            return (bool) filter_var($url, FILTER_VALIDATE_URL);
        }

        return (bool) preg_match('#^(/(?!/)|index\.php)#', $url);
    }

    /** @return int[] */
    private function intList(mixed $v): array
    {
        return \is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_scalar'))) : [];
    }

    /** @return array{installed:bool,published:bool} */
    private function joomlaModuleState(): array
    {
        $db        = $this->ctx()->db();
        $element   = self::JOOMLA_MODULE;
        $installed = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('module'))->where($db->quoteName('element') . ' = :e')->bind(':e', $element)
        )->loadResult() > 0;
        $published = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__modules'))
                ->where($db->quoteName('module') . ' = :m')->where($db->quoteName('client_id') . ' = 1')->where($db->quoteName('published') . ' = 1')->bind(':m', $element)
        )->loadResult() > 0;

        return ['installed' => $installed, 'published' => $published];
    }

    /**
     * Makes sure the administrator module instance exists in position "cpanel" (and is published), or unpublishes it.
     *
     * @return bool false when the Joomla module extension is not installed
     */
    private function publishJoomlaModule(bool $publish): bool
    {
        $db      = $this->ctx()->db();
        $element = self::JOOMLA_MODULE;
        if (!$this->joomlaModuleState()['installed']) {
            return false;
        }
        $ids = $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__modules'))
                ->where($db->quoteName('module') . ' = :m')->where($db->quoteName('client_id') . ' = 1')->bind(':m', $element)
        )->loadColumn() ?: [];

        if ($ids) {
            $state = $publish ? 1 : 0;
            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__modules'))->set($db->quoteName('published') . ' = :p')
                    ->whereIn($db->quoteName('id'), array_map('intval', $ids))->bind(':p', $state)
            )->execute();

            return true;
        }
        if (!$publish) {
            return true;
        }

        $title = 'UX Studio';
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__modules')
            . ' (' . implode(', ', $db->quoteName(['title', 'note', 'content', 'ordering', 'position', 'published', 'module', 'access', 'showtitle', 'params', 'client_id', 'language'])) . ') VALUES ('
            . $db->quote($title) . ", '', '', 1, " . $db->quote('cpanel') . ', 1, ' . $db->quote($element) . ", 3, 1, '{}', 1, " . $db->quote('*') . ')'
        )->execute();
        $moduleId = (int) $db->insertid();
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__modules_menu') . ' (' . $db->quoteName('moduleid') . ', ' . $db->quoteName('menuid') . ') VALUES (' . $moduleId . ', 0)'
        )->execute();

        return true;
    }
}

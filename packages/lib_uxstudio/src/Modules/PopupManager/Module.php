<?php

namespace Ux1\UxStudio\Modules\PopupManager;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Popup Manager: any number of popups with rich content, delay / scroll / exit-intent / immediate triggers, targeting by
 * menu item, URL, device and audience, scheduling, frequency capping (storage or cookie) and impression / click tracking.
 * The popup markup is rendered into the page hidden; the script and stylesheet are enqueued only on pages that have a match.
 */
final class Module extends BaseModule
{
    /** @var array<int,array<string,mixed>> popups selected for the current request */
    private array $selected = [];

    private bool $preview = false;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'prepare']);
            $ctx->on('onAfterRender', [$this, 'inject'], Priority::LOW);
        }
    }

    /** Decide which popups belong to this page and load the assets only when there is something to show. */
    public function prepare(Event $event): void
    {
        $this->selected = [];
        if (!Support::isPageView()) {
            return;
        }
        $ctx   = $this->ctx();
        $store = new Store($ctx->db());

        $previewId = Support::previewRequested('popup');
        if ($previewId > 0) {
            $popup = $store->get($previewId);
            if ($popup) {
                $this->selected = [$popup];
                $this->preview  = true;
            }
        } else {
            $this->selected = $this->match($store->active());
        }
        if ($this->selected) {
            $ctx->useStyle('popup-manager.css');
            $ctx->useScript('popup-manager.js');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $popups
     * @return array<int,array<string,mixed>>
     */
    private function match(array $popups): array
    {
        if (!$popups) {
            return [];
        }
        $app    = $this->ctx()->app();
        $item   = Support::activeMenuItem($app);
        $uri    = Uri::getInstance()->toString(['path', 'query']);
        $guest  = (bool) ($app->getIdentity()?->guest ?? true);
        $max    = max(1, min(5, (int) $this->ctx()->setting('max_per_page', 1)));
        $out    = [];

        foreach ($popups as $popup) {
            $audience = (string) $popup['audience'];
            if (($audience === 'guests' && !$guest) || ($audience === 'members' && $guest)) {
                continue;
            }
            if (!Support::pageMatches((string) $popup['menu_mode'], Support::ids($popup['menu_items']), Support::splitLines((string) $popup['url_patterns']), $item, $uri)) {
                continue;
            }
            $out[] = $popup;
            if (\count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** Append the hidden popup markup before </body>. */
    public function inject(Event $event): void
    {
        if (!$this->selected) {
            return;
        }
        $app  = $this->ctx()->app();
        $body = $app->getBody();
        if (!\is_string($body) || stripos($body, '</body>') === false) {
            return;
        }
        $options = [
            'storage' => (string) $this->ctx()->setting('storage', 'local'),
            'track'   => $this->ctx()->setting('tracking', true) ? Uri::root(true) . '/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=popup-manager/track' : '',
            'preview' => $this->preview,
        ];
        $html = '';
        foreach ($this->selected as $popup) {
            $html .= Renderer::html($popup, $options) . "\n";
        }
        // Replace the LAST </body> (a literal one inside a script string must not receive the markup).
        $pos = strripos($body, '</body>');
        $app->setBody(substr($body, 0, (int) $pos) . $html . substr($body, (int) $pos));
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'tracking', 'type' => 'toggle', 'label' => 'UXS_POPUP_MANAGER_TRACKING', 'help' => 'UXS_POPUP_MANAGER_TRACKING_HELP', 'default' => true],
            [
                'key' => 'storage', 'type' => 'select', 'label' => 'UXS_POPUP_MANAGER_STORAGE', 'help' => 'UXS_POPUP_MANAGER_STORAGE_HELP', 'default' => 'local',
                'options' => ['local' => 'UXS_POPUP_MANAGER_STORAGE_LOCAL', 'cookie' => 'UXS_POPUP_MANAGER_STORAGE_COOKIE'],
            ],
            ['key' => 'max_per_page', 'type' => 'number', 'label' => 'UXS_POPUP_MANAGER_MAX', 'help' => 'UXS_POPUP_MANAGER_MAX_HELP', 'default' => 1, 'min' => 1, 'max' => 5],
            ['key' => 'stats_retention_days', 'type' => 'number', 'label' => 'UXS_POPUP_MANAGER_RETENTION', 'help' => 'UXS_POPUP_MANAGER_RETENTION_HELP', 'default' => 365, 'min' => 0, 'max' => 3650],
        ];
    }

    public function routes(): array
    {
        $api = new Api($this->ctx());

        return [
            'GET popups'                       => [$api, 'list'],
            'POST popups'                      => [$api, 'create'],
            'GET popups/{id}'                  => [$api, 'get'],
            'POST popups/{id}'                 => [$api, 'update'],
            'POST popups/{id}/status'          => [$api, 'status'],
            'POST popups/{id}/duplicate'       => [$api, 'duplicate'],
            'POST popups/{id}/delete'          => ['handler' => [$api, 'delete'], 'acl' => 'core.admin'],
            'POST popups/{id}/reset-stats'     => ['handler' => [$api, 'resetStats'], 'acl' => 'core.admin'],
            'GET popups/{id}/preview-url'      => [$api, 'previewUrl'],
            'GET menu-items'                   => [$api, 'menuItems'],
        ];
    }

    public function publicRoutes(): array
    {
        $api = new Api($this->ctx());

        // Browser beacon: no auth, a present Origin must be this site, 120 events per minute and IP.
        return ['POST track' => ['handler' => [$api, 'track'], 'auth' => 'none', 'limit' => [120, 60], 'max_body' => 1024]];
    }

    public function migrations(): array
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_popups` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(190) NOT NULL DEFAULT \'\', `status` VARCHAR(10) NOT NULL DEFAULT \'draft\', '
                . '`priority` SMALLINT NOT NULL DEFAULT 0, `starts_at` DATETIME NULL DEFAULT NULL, `ends_at` DATETIME NULL DEFAULT NULL, '
                . '`config` MEDIUMTEXT NOT NULL, `created` DATETIME NOT NULL, `modified` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_status` (`status`, `priority`))' . $table,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_popup_stats` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `popup_id` INT UNSIGNED NOT NULL, `event` VARCHAR(10) NOT NULL DEFAULT \'\', `created` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_popup_event` (`popup_id`, `event`), KEY `idx_created` (`created`))' . $table,
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_popup_stats', 'column' => 'created', 'days' => fn (): int => (int) $this->ctx()->setting('stats_retention_days', 365)]];
    }

    public function dashboardWidget(): ?array
    {
        $store = new Store($this->ctx()->db());
        $count = $store->countActive();

        return [
            'title'  => Text::_('UXS_POPUP_MANAGER_NAME'),
            'value'  => $count,
            'text'   => Text::sprintf('UXS_POPUP_MANAGER_WIDGET', $store->recent30()),
            'status' => $count > 0 ? 'ok' : 'warn',
        ];
    }
}

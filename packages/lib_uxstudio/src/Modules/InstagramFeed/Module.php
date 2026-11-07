<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Instagram Feed: the latest posts of an Instagram account on the site (grid or carousel).
 *
 * Source: the central app (CA) `instagram_api` through CaSource. The account is connected with a browser hand-over to the
 * CA (`instagram_auth`); the site only stores the CA `connection_id`, never a Meta token. Posts are cached in
 * #__uxstudio_instagram_media and their images are copied to images/uxstudio-instagram/, so the front end needs neither
 * the CA nor Instagram at view time (it keeps working when the site is not paired or the CA is down).
 * Output: mod_uxstudio_instagram or the {uxstudio_instagram} tag in content.
 *
 * The cache is never dropped on disable (only by "Disconnect").
 */
final class Module extends BaseModule
{
    /** `sync_interval` setting => hours between automatic synchronisations. */
    private const INTERVAL_HOURS = ['1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24];

    private const NONCE_TTL = 900;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            // {uxstudio_instagram layout=carousel count=6} in articles and custom modules.
            $ctx->on('onContentPrepare', [$this, 'contentPrepare']);
        }
    }

    // ---------------------------------------------------------------- settings

    public function settingsSchema(): array
    {
        $p = 'UXS_INSTAGRAM_FEED_';

        return [
            ['key' => 'layout', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'LAYOUT', 'help' => $p . 'LAYOUT_HELP', 'default' => 'grid',
                'options' => ['grid' => $p . 'LAYOUT_GRID', 'carousel' => $p . 'LAYOUT_CAROUSEL']],
            ['key' => 'count', 'type' => 'number', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'COUNT', 'help' => $p . 'COUNT_HELP', 'default' => 12, 'min' => 1, 'max' => 50],
            ['key' => 'columns', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'COLUMNS', 'help' => $p . 'COLUMNS_HELP', 'default' => '4',
                'options' => array_combine(array_map('strval', Renderer::COLUMNS), array_map('strval', Renderer::COLUMNS))],
            ['key' => 'columns_tablet', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'COLUMNS_TABLET', 'default' => 'auto',
                'options' => ['auto' => $p . 'COLUMNS_AUTO'] + array_combine(array_map('strval', Renderer::COLUMNS_SMALL), array_map('strval', Renderer::COLUMNS_SMALL))],
            ['key' => 'columns_mobile', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'COLUMNS_MOBILE', 'default' => 'auto',
                'options' => ['auto' => $p . 'COLUMNS_AUTO'] + array_combine(array_map('strval', Renderer::COLUMNS_SMALL), array_map('strval', Renderer::COLUMNS_SMALL))],
            ['key' => 'gap', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'GAP', 'default' => '8',
                'options' => array_combine(array_map('strval', Renderer::GAPS), array_map(static fn (int $g): string => $g . ' px', Renderer::GAPS))],
            ['key' => 'show_caption', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_CAPTION', 'default' => false],
            ['key' => 'caption_length', 'type' => 'number', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'CAPTION_LENGTH', 'default' => 100, 'min' => 20, 'max' => 2000, 'show_if' => ['key' => 'show_caption', 'truthy' => true]],
            ['key' => 'show_likes', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_LIKES', 'help' => $p . 'SHOW_LIKES_HELP', 'default' => false],
            ['key' => 'show_follow', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_FOLLOW', 'help' => $p . 'SHOW_FOLLOW_HELP', 'default' => true],
            ['key' => 'link_target', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'LINK_TARGET', 'default' => '_blank',
                'options' => ['_blank' => $p . 'TARGET_BLANK', '_self' => $p . 'TARGET_SELF']],

            ['key' => 'media_types', 'type' => 'multiselect', 'section' => $p . 'SECTION_CONTENT', 'label' => $p . 'MEDIA_TYPES', 'help' => $p . 'MEDIA_TYPES_HELP', 'default' => Item::TYPES,
                'options' => ['IMAGE' => $p . 'TYPE_IMAGE', 'VIDEO' => $p . 'TYPE_VIDEO', 'CAROUSEL_ALBUM' => $p . 'TYPE_ALBUM']],
            ['key' => 'include_hashtags', 'type' => 'text', 'section' => $p . 'SECTION_CONTENT', 'label' => $p . 'INCLUDE_TAGS', 'help' => $p . 'INCLUDE_TAGS_HELP', 'default' => '', 'maxlength' => 300],
            ['key' => 'exclude_hashtags', 'type' => 'text', 'section' => $p . 'SECTION_CONTENT', 'label' => $p . 'EXCLUDE_TAGS', 'help' => $p . 'EXCLUDE_TAGS_HELP', 'default' => '', 'maxlength' => 300],

            ['key' => 'auto_sync', 'type' => 'toggle', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'AUTO_SYNC', 'help' => $p . 'AUTO_SYNC_HELP', 'default' => true],
            ['key' => 'sync_interval', 'type' => 'select', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'SYNC_INTERVAL', 'default' => '6h', 'show_if' => ['key' => 'auto_sync', 'truthy' => true],
                'options' => ['1h' => $p . 'EVERY_HOUR', '6h' => $p . 'EVERY_6H', '12h' => $p . 'EVERY_12H', '24h' => $p . 'EVERY_DAY']],
            ['key' => 'sync_limit', 'type' => 'number', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'SYNC_LIMIT', 'help' => $p . 'SYNC_LIMIT_HELP', 'default' => 25, 'min' => 1, 'max' => 100],
            ['key' => 'store_images', 'type' => 'toggle', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'STORE_IMAGES', 'help' => $p . 'STORE_IMAGES_HELP', 'default' => true],
        ];
    }

    // ---------------------------------------------------------------- API (administrator)

    public function routes(): array
    {
        return [
            'GET status'                 => [$this, 'status'],
            'POST connect'               => ['handler' => [$this, 'connect'], 'acl' => 'core.admin'],
            'POST disconnect'            => ['handler' => [$this, 'disconnect'], 'acl' => 'core.admin'],
            'POST sync'                  => [$this, 'syncRoute'],
            'GET media'                  => [$this, 'listMedia'],
            'POST media/{id}/toggle-hidden' => [$this, 'toggleHidden'],
        ];
    }

    /** Public: the browser comes back here from the central app after the Instagram login. */
    public function publicRoutes(): array
    {
        return [
            'GET callback' => ['handler' => [$this, 'callback'], 'auth' => 'none', 'limit' => [20, 60], 'max_body' => 1024],
        ];
    }

    private function store(): MediaStore
    {
        return new MediaStore($this->ctx()->db());
    }

    private function connectionId(): int
    {
        // The connection id is module state (not a setting a person edits). The setting of earlier versions is still honoured.
        $stored = $this->store()->getMeta('connection_id');
        if ($stored !== '') {
            return max(0, (int) $stored);
        }

        return max(0, (int) $this->ctx()->setting('connection_id', 0));
    }

    public function status(ApiRequest $r): array
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        $conn  = $this->connectionId();
        $prof  = $store->getJson('profile');

        return [
            'ca'         => ['availability' => CaSource::availability($ctx)],
            'connection' => [
                'id'              => $conn,
                'connected'       => $conn > 0,
                'username'        => (string) ($prof['username'] ?? ''),
                'name'            => (string) ($prof['name'] ?? ''),
                'account_type'    => (string) ($prof['account_type'] ?? ''),
                'followers_count' => $prof['followers_count'] ?? null,
                'media_count'     => $prof['media_count'] ?? null,
                'connected_at'    => $store->getMeta('connected_at'),
                'lost'            => $store->getMeta('connection_lost') === '1',
            ],
            'cache' => [
                'posts'        => $store->count(),
                'hidden'       => $store->count(true),
                'local_images' => $store->countWithLocalFile(),
                'last_sync'    => [
                    'at'      => $store->getMeta('last_sync_at'),
                    'status'  => $store->getMeta('last_sync_status'),
                    'message' => $store->getMeta('last_sync_message'),
                ],
            ],
            'tag'       => '{uxstudio_instagram}',
            'image_dir' => ImageCache::DIR,
            'store_images' => (bool) $ctx->setting('store_images', true),
        ];
    }

    /** POST connect: remembers a one-time nonce and returns the CA URL the browser has to open. */
    public function connect(ApiRequest $r): array
    {
        $ctx    = $this->ctx();
        $source = CaSource::create($ctx, 0);
        if ($source === null) {
            throw new ApiException($ctx->t('UXS_INSTAGRAM_FEED_ERR_NOT_PAIRED'), 424, 'not_paired');
        }
        $store = $this->store();
        $nonce = bin2hex(random_bytes(16));
        $store->setMeta('oauth_nonce', $nonce);
        $store->setMeta('oauth_nonce_at', (string) time());

        $return = $source->siteUrl() . '/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=instagram-feed/callback';

        return ['auth_url' => $source->authorizeUrl($return, $nonce)];
    }

    /**
     * GET callback (public): the central app redirects the browser here with a signed payload. Verifies it, stores the
     * connection id and sends the browser to the module screen.
     */
    public function callback(ApiRequest $r): array
    {
        $ctx   = $this->ctx();
        $app   = $ctx->app();
        $store = $this->store();
        $back  = Uri::root() . 'administrator/index.php?option=com_uxstudio#/module?id=instagram-feed';

        try {
            $source = CaSource::create($ctx, 0);
            $nonce  = $store->getMeta('oauth_nonce');
            if ($source === null || $nonce === '' || time() - (int) $store->getMeta('oauth_nonce_at') > self::NONCE_TTL) {
                throw new ApiException('expired', 403, 'invalid_callback');
            }

            // Raw query (no input filtering) so the signature matches. An unsigned `error` must not burn the pending nonce:
            // anybody could send it, so the nonce is only consumed by a verified answer.
            $query = $r->queryAll();
            if (!empty($query['error'])) {
                throw new ApiException('denied', 403, 'denied');
            }
            foreach (['option', 'task', 'format', 'route', 'Itemid', 'lang'] as $own) {
                unset($query[$own]);
            }
            $source->verifyReturn($query, $nonce);
            // The nonce is single use.
            $store->deleteMeta('oauth_nonce');

            $id = (int) ($query['connection_id'] ?? 0);
            if ($id <= 0) {
                throw new ApiException('connection', 403, 'invalid_callback');
            }
            if ($this->connectionId() !== $id) {
                // Another account: the posts of the previous one must not mix with the new ones.
                $store->clear();
                ImageCache::prune([]);
                $store->deleteMeta('last_sync_at');
                $store->deleteMeta('last_sync_status');
                $store->deleteMeta('last_sync_message');
            }
            $store->setMeta('connection_id', (string) $id);
            $store->deleteMeta('connection_lost');
            $store->setMeta('connected_at', Factory::getDate()->toSql());
            $store->setMeta('profile', (string) json_encode(Syncer::cleanProfile([
                'username'     => $query['ig_username'] ?? '',
                'account_type' => $query['ig_account_type'] ?? '',
            ]), JSON_UNESCAPED_UNICODE));

            $app->redirect($back . '&ig=connected');
        } catch (ApiException $e) {
            $app->redirect($back . '&ig=error&reason=' . rawurlencode($e->errorCode()));
        }

        return [];
    }

    /** POST disconnect: revokes the connection at the central app (best effort) and forgets everything about the account. */
    public function disconnect(ApiRequest $r): array
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        $conn  = $this->connectionId();

        $revoked = false;
        if ($conn > 0) {
            try {
                CaSource::create($ctx, $conn)?->disconnect();
                $revoked = true;
            } catch (\Throwable $e) {
                // The CA may be unreachable or already forgot the connection: still clean up locally.
            }
        }
        $store->clear();
        ImageCache::prune([]);
        foreach (['profile', 'connected_at', 'connection_lost', 'last_sync_at', 'last_sync_status', 'last_sync_message', 'oauth_nonce', 'oauth_nonce_at'] as $name) {
            $store->deleteMeta($name);
        }

        // '0' (not removed) so a connection_id left in the settings of an earlier version is not picked up again.
        $store->setMeta('connection_id', '0');

        return ['connected' => false, 'revoked' => $revoked];
    }

    public function syncRoute(ApiRequest $r): array
    {
        return $this->sync();
    }

    /**
     * Pulls profile and latest posts from the central app into the cache.
     *
     * @return array{fetched:int,new:int,updated:int,images:int,removed:int,skipped:int}
     * @throws ApiException
     */
    public function sync(): array
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        $conn  = $this->connectionId();
        if ($conn <= 0) {
            throw new ApiException($ctx->t('UXS_INSTAGRAM_FEED_ERR_NO_CONNECTION'), 424, 'no_connection');
        }
        $source = CaSource::create($ctx, $conn);
        if ($source === null) {
            throw new ApiException($ctx->t('UXS_INSTAGRAM_FEED_ERR_NOT_PAIRED'), 424, 'not_paired');
        }
        if (!$store->acquireLock('sync_lock', 300)) {
            throw new ApiException($ctx->t('UXS_INSTAGRAM_FEED_ERR_BUSY'), 409, 'busy');
        }

        $store->setMeta('last_sync_at', Factory::getDate()->toSql());
        try {
            $syncer = new Syncer($store, new ImageCache($ctx->http()));
            $res    = $syncer->run($source, (int) $ctx->setting('sync_limit', 25), (bool) $ctx->setting('store_images', true));
        } catch (\Throwable $e) {
            $store->setMeta('last_sync_status', 'error');
            $store->setMeta('last_sync_message', mb_substr($e->getMessage(), 0, 250));
            if ($e instanceof ApiException && $e->errorCode() === 'connection_lost') {
                $store->setMeta('connection_lost', '1');
            } elseif ($e instanceof ApiException && $e->errorCode() === 'ca_auth') {
                $store->deleteMeta('connection_lost'); // a pairing problem, not a lost account
            }
            throw $e;
        } finally {
            $store->deleteMeta('sync_lock');
        }
        $store->deleteMeta('connection_lost');
        $store->setMeta('last_sync_status', 'ok');
        $store->setMeta('last_sync_message', $res['new'] . ' new, ' . $res['updated'] . ' updated, ' . $res['removed'] . ' removed, ' . $res['images'] . ' images');

        return $res;
    }

    public function listMedia(ApiRequest $r): array
    {
        $page = $this->store()->page(max(1, $r->int('page', 1)), $r->int('per_page', 24));
        foreach ($page['items'] as &$item) {
            $item['image_url'] = Renderer::imageUrl($item);
            $item['local']     = $item['local_file'] !== '' && ImageCache::exists($item['local_file']);
            unset($item['local_file'], $item['media_url'], $item['thumbnail_url']);
        }
        unset($item);

        return $page;
    }

    public function toggleHidden(ApiRequest $r): array
    {
        $store = $this->store();
        $id    = (int) $r->param('id');
        $row   = $store->get($id);
        if ($row === null) {
            throw new ApiException($this->ctx()->t('UXS_INSTAGRAM_FEED_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $store->setHidden($id, !$row['is_hidden']);

        return ['id' => $id, 'is_hidden' => !$row['is_hidden']];
    }

    // ---------------------------------------------------------------- scheduler / widget

    public function tasks(): array
    {
        // Runs hourly, the callable honours the "interval" setting itself.
        return ['sync' => ['label' => 'UXS_INSTAGRAM_FEED_JOB_SYNC', 'callable' => [$this, 'syncJob'], 'interval' => 'PT1H']];
    }

    public function syncJob(): string
    {
        $ctx   = $this->ctx();
        $store = $this->store();
        if (!$ctx->setting('auto_sync', true) || $this->connectionId() <= 0) {
            return 'skipped (auto sync off or no account connected)';
        }
        if (CaSource::availability($ctx) !== 'ok') {
            return 'skipped (site is not connected to the central app)';
        }
        $hours = self::INTERVAL_HOURS[(string) $ctx->setting('sync_interval', '6h')] ?? 6;
        $last  = strtotime($store->getMeta('last_sync_at') . ' UTC');
        // A failed run is retried at the next hourly tick, a successful one waits for the interval.
        if ($last && $store->getMeta('last_sync_status') === 'ok' && time() - $last < $hours * 3600 - 300) {
            return 'skipped (synchronised recently)';
        }
        try {
            $res = $this->sync();
        } catch (\Throwable $e) {
            // Recorded in the module state (shown in the administration). Returned as text, not thrown: a failing CA must not
            // make the Joomla scheduler e-mail the administrators on every run.
            return 'error: ' . $e->getMessage();
        }

        return $res['new'] . ' new, ' . $res['updated'] . ' updated, ' . $res['removed'] . ' removed, ' . $res['images'] . ' images';
    }

    public function dashboardWidget(): ?array
    {
        try {
            $store = $this->store();
            $posts = $store->count();
            $lost  = $store->getMeta('connection_lost') === '1';
            $conn  = $this->connectionId() > 0;
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => $this->ctx()->t('UXS_INSTAGRAM_FEED_NAME'),
            'value'  => $conn ? $posts : '-',
            'text'   => $conn ? Text::plural('UXS_INSTAGRAM_FEED_WIDGET_TEXT', $posts) : $this->ctx()->t('UXS_INSTAGRAM_FEED_WIDGET_NOT_CONNECTED'),
            'status' => !$conn || $lost ? 'warn' : ($posts > 0 ? 'ok' : 'warn'),
        ];
    }

    // ---------------------------------------------------------------- migrations

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_instagram_media` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`instagram_id` VARCHAR(64) NOT NULL, '
                . '`media_type` VARCHAR(24) NOT NULL DEFAULT \'IMAGE\', '
                . '`media_url` VARCHAR(1000) NOT NULL DEFAULT \'\', '
                . '`thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT \'\', '
                . '`permalink` VARCHAR(1000) NOT NULL DEFAULT \'\', '
                . '`caption` MEDIUMTEXT NULL, '
                . '`hashtags` TEXT NULL, '
                . '`media_timestamp` DATETIME NULL, '
                . '`like_count` INT UNSIGNED NULL, '
                . '`comments_count` INT UNSIGNED NULL, '
                . '`local_file` VARCHAR(64) NOT NULL DEFAULT \'\', '
                . '`is_hidden` TINYINT(1) NOT NULL DEFAULT 0, '
                . '`synced_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_instagram_id` (`instagram_id`), KEY `idx_visible_time` (`is_hidden`, `media_timestamp`)'
                . ')' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_instagram_meta` ('
                . '`name` VARCHAR(64) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`name`)'
                . ')' . $tail,
            ],
            2 => ['ALTER TABLE `#__uxstudio_instagram_media` ADD COLUMN `dl_fail` TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ];
    }

    // ---------------------------------------------------------------- front end

    /**
     * Rendered feed for mod_uxstudio_instagram and the content tag. $o overrides the module settings; empty / null values
     * mean "use the setting": layout, count, columns, columns_tablet, columns_mobile, show_likes, gap, show_caption, show_follow, heading, css_class, hashtags, exclude.
     *
     * @param array<string,mixed> $o
     */
    public function renderFront(array $o = []): string
    {
        $ctx  = $this->ctx();
        $pick = static fn (string $key, mixed $setting): mixed => ($o[$key] ?? '') === '' || $o[$key] === null ? $setting : $o[$key];
        $bool = static fn (mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN);

        $count   = max(1, min(50, (int) $pick('count', $ctx->setting('count', 12))));
        $include = Item::tagList((string) $pick('hashtags', $ctx->setting('include_hashtags', '')));
        $exclude = Item::tagList((string) $pick('exclude', $ctx->setting('exclude_hashtags', '')));
        $types   = array_values(array_intersect((array) $ctx->setting('media_types', Item::TYPES), Item::TYPES));

        $store = $this->store();
        // Hashtag filters work on a larger window than the number to show.
        $rows = $types ? $store->visible($types, $include || $exclude ? 200 : $count) : [];
        if ($include || $exclude) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($include, $exclude): bool {
                if ($exclude && array_intersect($row['hashtags'], $exclude)) {
                    return false;
                }

                return !$include || array_intersect($row['hashtags'], $include);
            }));
        }
        $rows = \array_slice($rows, 0, $count);

        if (!$rows) {
            return $this->emptyNotice();
        }

        $layout = (string) $pick('layout', $ctx->setting('layout', 'grid'));
        $prof   = $store->getJson('profile');
        $html   = Renderer::render($rows, [
            'layout'         => $layout,
            'columns'        => (int) $pick('columns', $ctx->setting('columns', 4)),
            'columns_tablet' => (string) $pick('columns_tablet', $ctx->setting('columns_tablet', 'auto')),
            'columns_mobile' => (string) $pick('columns_mobile', $ctx->setting('columns_mobile', 'auto')),
            'show_likes'     => $bool($pick('show_likes', $ctx->setting('show_likes', false))),
            'allow_remote'   => !$ctx->setting('store_images', true),
            'gap'            => (int) $pick('gap', $ctx->setting('gap', 8)),
            'show_caption'   => $bool($pick('show_caption', $ctx->setting('show_caption', false))),
            'caption_length' => (int) $ctx->setting('caption_length', 100),
            'show_follow'    => $bool($pick('show_follow', $ctx->setting('show_follow', true))),
            'link_target'    => (string) $ctx->setting('link_target', '_blank'),
            'heading'        => (string) ($o['heading'] ?? ''),
            'css_class'      => (string) ($o['css_class'] ?? ''),
            'username'       => (string) ($prof['username'] ?? ''),
        ]);

        $ctx->useStyle('instagram.css');
        if ($layout === 'carousel') {
            $ctx->useScript('instagram.js');
        }

        return $html;
    }

    /** Nothing to show: visitors see nothing, administrators see what is missing. */
    private function emptyNotice(): string
    {
        $ctx = $this->ctx();
        try {
            $identity = $ctx->app()->getIdentity();
            if (!$identity || !$identity->authorise('core.manage', 'com_uxstudio')) {
                return '';
            }
        } catch (\Throwable $e) {
            return '';
        }

        if ($this->connectionId() <= 0) {
            $key = CaSource::availability($ctx) === 'ok' ? 'UXS_INSTAGRAM_FEED_NOTICE_NOT_CONNECTED' : 'UXS_INSTAGRAM_FEED_NOTICE_NO_CA';
        } else {
            $key = 'UXS_INSTAGRAM_FEED_NOTICE_NO_POSTS';
        }
        $ctx->useStyle('instagram.css');

        return Renderer::notice($ctx->t($key));
    }

    /** onContentPrepare: replaces {uxstudio_instagram key=value ...} in the text of an article / custom module. */
    public function contentPrepare(Event $event): void
    {
        $item = $event->hasArgument('subject') ? $event->getArgument('subject') : $event->getArgument(1);
        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !str_contains($item->text, '{uxstudio_instagram')) {
            return;
        }

        $item->text = preg_replace_callback(
            '/\{uxstudio_instagram([^}]*)\}/',
            function (array $m): string {
                $o = [];
                if (preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $m[1], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $key = strtolower($p[1]);
                        $val = ($p[4] ?? '') !== '' ? $p[4] : (($p[3] ?? '') !== '' ? $p[3] : ($p[2] ?? ''));
                        if (\in_array($key, ['layout', 'count', 'columns', 'columns_tablet', 'columns_mobile', 'show_likes', 'gap', 'show_caption', 'show_follow', 'heading', 'hashtags', 'exclude'], true)) {
                            $o[$key] = $val;
                        }
                    }
                }

                try {
                    return $this->renderFront($o);
                } catch (\Throwable $e) {
                    return '';
                }
            },
            $item->text
        ) ?? $item->text;
    }
}

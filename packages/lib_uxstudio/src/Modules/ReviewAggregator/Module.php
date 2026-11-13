<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Review Aggregator: reviews from Google / Facebook / Mapy.cz pulled from the central app (CA) `reviews_api` through
 * the CaConnector, or entered / imported by hand, stored in #__uxstudio_reviews and shown through mod_uxstudio_reviews
 * (or the {uxstudio_reviews} tag in content) with schema.org AggregateRating.
 *
 * The review table is never dropped or purged (not on disable, not by retention).
 */
final class Module extends BaseModule
{
    /** `fetch_interval` setting => minimum hours between automatic fetches. */
    private const INTERVAL_HOURS = ['1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24, 'weekly' => 168];

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            // {uxstudio_reviews count=5 layout=list} in articles and custom modules.
            $ctx->on('onContentPrepare', [$this, 'contentPrepare']);
        }
    }

    // ---------------------------------------------------------------- settings

    public function settingsSchema(): array
    {
        $p = 'UXS_REVIEW_AGGREGATOR_';

        return [
            ['key' => 'layout', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'LAYOUT', 'help' => $p . 'LAYOUT_HELP', 'default' => 'grid', 'options' => [
                'grid' => $p . 'LAYOUT_GRID', 'list' => $p . 'LAYOUT_LIST', 'slider' => $p . 'LAYOUT_SLIDER', 'badge' => $p . 'LAYOUT_BADGE',
            ]],
            ['key' => 'per_page', 'type' => 'number', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'PER_PAGE', 'help' => $p . 'PER_PAGE_HELP', 'default' => 10, 'min' => 1, 'max' => 100],
            ['key' => 'min_rating', 'type' => 'number', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'MIN_RATING', 'help' => $p . 'MIN_RATING_HELP', 'default' => 1, 'min' => 1, 'max' => 5],
            ['key' => 'order_by', 'type' => 'select', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'ORDER', 'default' => 'newest', 'options' => [
                'newest' => $p . 'ORDER_NEWEST', 'oldest' => $p . 'ORDER_OLDEST', 'best_rating' => $p . 'ORDER_BEST',
            ]],
            ['key' => 'show_summary', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_SUMMARY', 'help' => $p . 'SHOW_SUMMARY_HELP', 'default' => true],
            ['key' => 'show_platform', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_PLATFORM', 'default' => true],
            ['key' => 'show_date', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_DATE', 'default' => true],
            ['key' => 'show_avatar', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_AVATAR', 'default' => true],
            ['key' => 'show_stars', 'type' => 'toggle', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'SHOW_STARS', 'default' => true],
            ['key' => 'text_limit', 'type' => 'number', 'section' => $p . 'SECTION_DISPLAY', 'label' => $p . 'TEXT_LIMIT', 'help' => $p . 'TEXT_LIMIT_HELP', 'default' => 200, 'min' => 0, 'max' => 2000],

            ['key' => 'central_profile_id', 'type' => 'text', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'PROFILE_ID', 'help' => $p . 'PROFILE_ID_HELP', 'default' => '', 'maxlength' => 12],
            ['key' => 'google_url', 'type' => 'text', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'GOOGLE_URL', 'help' => $p . 'GOOGLE_URL_HELP', 'default' => '', 'maxlength' => 500],
            ['key' => 'auto_fetch', 'type' => 'toggle', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'AUTO_FETCH', 'help' => $p . 'AUTO_FETCH_HELP', 'default' => true],
            ['key' => 'fetch_interval', 'type' => 'select', 'section' => $p . 'SECTION_SOURCE', 'label' => $p . 'FETCH_INTERVAL', 'default' => '24h', 'show_if' => ['key' => 'auto_fetch', 'truthy' => true], 'options' => [
                '1h' => $p . 'EVERY_HOUR', '6h' => $p . 'EVERY_6H', '12h' => $p . 'EVERY_12H', '24h' => $p . 'EVERY_DAY', 'weekly' => $p . 'EVERY_WEEK',
            ]],

            ['key' => 'schema_enabled', 'type' => 'toggle', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA', 'help' => $p . 'SCHEMA_HELP', 'default' => true],
            ['key' => 'schema_type', 'type' => 'select', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_TYPE', 'help' => $p . 'SCHEMA_TYPE_HELP', 'default' => 'Organization', 'show_if' => ['key' => 'schema_enabled', 'truthy' => true], 'options' => [
                'Organization' => 'Organization', 'LocalBusiness' => 'LocalBusiness', 'Product' => 'Product', 'Service' => 'Service',
            ]],
            ['key' => 'schema_name', 'type' => 'text', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_NAME', 'help' => $p . 'SCHEMA_NAME_HELP', 'default' => '', 'maxlength' => 190, 'show_if' => ['key' => 'schema_enabled', 'truthy' => true]],
            ['key' => 'schema_image', 'type' => 'text', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_IMAGE', 'help' => $p . 'SCHEMA_IMAGE_HELP', 'default' => '', 'maxlength' => 500, 'show_if' => ['key' => 'schema_type', 'equals' => 'LocalBusiness']],
            ['key' => 'schema_reviews', 'type' => 'toggle', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_REVIEWS', 'help' => $p . 'SCHEMA_REVIEWS_HELP', 'default' => false, 'show_if' => ['key' => 'schema_enabled', 'truthy' => true]],
        ];
    }

    // ---------------------------------------------------------------- API (administrator)

    public function routes(): array
    {
        return [
            'GET reviews'                       => [$this, 'listReviews'],
            'POST reviews'                      => [$this, 'saveReview'],
            'POST reviews/{id}'                 => [$this, 'saveReview'],
            'POST reviews/{id}/toggle-visibility' => [$this, 'toggleVisibility'],
            'POST reviews/{id}/delete'          => ['handler' => [$this, 'deleteReview'], 'acl' => 'core.admin'],
            'POST import'                       => ['handler' => [$this, 'importReviews'], 'acl' => 'core.admin'],
            'GET stats'                         => [$this, 'stats'],
            'POST fetch'                        => [$this, 'fetchRoute'],
            'POST request-scrape'               => [$this, 'requestScrapeRoute'],
        ];
    }

    private function store(): ReviewStore
    {
        return new ReviewStore($this->ctx()->db());
    }

    public function listReviews(ApiRequest $r): array
    {
        $visible = $r->input('visible');
        $filters = [
            'source'     => ReviewStore::normalizeSource($r->string('source')),
            'visible'    => $visible === null || $visible === '' ? null : filter_var($visible, FILTER_VALIDATE_BOOLEAN),
            'min_rating' => max(0, min(5, $r->int('min_rating'))),
            'q'          => mb_substr(trim($r->string('q')), 0, 100),
        ];
        $result            = $this->store()->list($filters, max(1, $r->int('page', 1)), $r->int('per_page', 20));
        $result['sources'] = $this->store()->sources();

        return $result;
    }

    /** POST reviews (new manual review) and POST reviews/{id} (edit a manual review). */
    public function saveReview(ApiRequest $r): array
    {
        $store = $this->store();
        $id    = (int) $r->param('id', '0');
        if ($id > 0) {
            $existing = $store->get($id);
            if ($existing === null) {
                throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_NOT_FOUND'), 404, 'not_found');
            }
            if (!\in_array($existing['origin'], ['manual', 'import'], true)) {
                throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_NOT_MANUAL'), 409, 'not_manual');
            }
        }

        $rating = $r->int('rating');
        if ($rating < 1 || $rating > 5) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_RATING'), 422, 'invalid_rating');
        }
        $date = trim($r->string('review_date'));
        if ($date !== '' && strtotime($date) === false) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_DATE'), 422, 'invalid_date');
        }
        $row = ReviewStore::normalize([
            'source'      => $r->string('source', 'manual'),
            'author'      => $r->string('author'),
            'rating'      => $rating,
            'text'        => $r->string('text'),
            'review_date' => $date,
        ], 'manual');
        if ($row === null || ($row['author'] === '' && $row['text'] === '')) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_EMPTY'), 422, 'empty');
        }
        if ($id === 0) {
            $row['review_date'] ??= Factory::getDate()->toSql();
        }

        $hash = ReviewStore::dedupHash($row);
        if ($store->hashExists($hash, $id)) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_DUPLICATE'), 409, 'duplicate');
        }

        try {
            if ($id > 0) {
                $store->updateManual($id, $row, $hash);
            } else {
                $store->upsertMany([$row], 'manual');
                $id = (int) $this->ctx()->db()->insertid();
            }
        } catch (\Throwable $e) {
            // A parallel request (double click) won the race for the unique dedup key.
            if (stripos($e->getMessage(), 'duplicate') !== false || (int) $e->getCode() === 1062) {
                throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_DUPLICATE'), 409, 'duplicate');
            }
            throw $e;
        }

        return (array) $store->get($id);
    }

    public function toggleVisibility(ApiRequest $r): array
    {
        $store = $this->store();
        $id    = (int) $r->param('id');
        $row   = $store->get($id);
        if ($row === null) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $store->setVisible($id, !$row['visible']);

        return (array) $store->get($id);
    }

    public function deleteReview(ApiRequest $r): array
    {
        $store = $this->store();
        $id    = (int) $r->param('id');
        if ($store->get($id) === null) {
            throw new ApiException($this->ctx()->t('UXS_REVIEW_AGGREGATOR_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $store->delete($id);

        return ['deleted' => $id];
    }

    /** POST import {format: csv|json, data: string, source?: string} */
    public function importReviews(ApiRequest $r): array
    {
        $format = $r->string('format', 'csv') === 'json' ? 'json' : 'csv';
        $source = ReviewStore::normalizeSource($r->string('source', 'manual')) ?: 'manual';
        $items  = Importer::parse($format, $r->string('data'));

        $rows    = [];
        $skipped = 0;
        foreach ($items as $item) {
            $row = ReviewStore::normalize($item, $source);
            if ($row === null || $row['rating'] < 1 || ($row['author'] === '' && $row['text'] === '')) {
                ++$skipped;
                continue;
            }
            $rows[] = $row;
        }
        $result = $this->store()->upsertMany($rows, 'import');

        return $result + ['skipped' => $skipped, 'total' => \count($items)];
    }

    public function stats(ApiRequest $r): array
    {
        $store    = $this->store();
        $ctx      = $this->ctx();
        $profile  = (int) $ctx->setting('central_profile_id', 0);

        return $store->stats() + [
            'ca' => [
                'available'  => CentralSource::available($ctx),
                'profile_id' => $profile,
                'last_fetch' => [
                    'at'      => $store->getMeta('last_fetch_at'),
                    'status'  => $store->getMeta('last_fetch_status'),
                    'message' => $store->getMeta('last_fetch_message'),
                ],
            ],
            'shortcode' => '{uxstudio_reviews}',
        ];
    }

    public function fetchRoute(ApiRequest $r): array
    {
        return $this->fetch();
    }

    public function requestScrapeRoute(ApiRequest $r): array
    {
        $ctx = $this->ctx();
        [$placeId, $mapsUrl] = CentralSource::resolvePlace((string) $ctx->setting('google_url', ''));
        if ($placeId === '' && $mapsUrl === '') {
            throw new ApiException($ctx->t('UXS_REVIEW_AGGREGATOR_ERR_NO_PLACE'), 424, 'no_place');
        }
        $name   = $ctx->app()->get('sitename', 'Website') . ' - Google';
        $result = CentralSource::requestScrape($ctx, $this->store(), $placeId, $mapsUrl, $name);

        if ($result['profile_id'] > 0 && (int) $ctx->setting('central_profile_id', 0) <= 0) {
            $ctx->kernel()->settings()->save($ctx->id(), ['central_profile_id' => (string) $result['profile_id']]);
        }

        return $result;
    }

    /**
     * Pulls the stored reviews of the configured CA profile and upserts them into the local cache.
     *
     * @return array{fetched:int,updated:int}
     */
    public function fetch(bool $useEtag = false): array
    {
        $ctx       = $this->ctx();
        $store     = $this->store();
        $profileId = (int) $ctx->setting('central_profile_id', 0);
        if ($profileId <= 0) {
            throw new ApiException($ctx->t('UXS_REVIEW_AGGREGATOR_ERR_NO_PROFILE'), 424, 'no_profile');
        }

        $store->setMeta('last_fetch_at', Factory::getDate()->toSql());
        try {
            $etag = $useEtag ? $store->getMeta('last_etag') : '';
            $read = CentralSource::fetchAll($ctx, $store, $profileId, $etag);
            if ($read['not_modified']) {
                $store->setMeta('last_fetch_status', 'ok');
                $store->setMeta('last_fetch_message', '0 new, 0 updated (not modified)');

                return ['fetched' => 0, 'updated' => 0];
            }
            $items = $read['items'];
            $rows  = [];
            foreach ($items as $item) {
                $row = ReviewStore::normalize($item);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $result = $store->upsertMany($rows, 'ca');
            $store->setMeta('last_etag', $read['etag']);
        } catch (\Throwable $e) {
            $store->setMeta('last_fetch_status', 'error');
            $store->setMeta('last_fetch_message', mb_substr($e->getMessage(), 0, 250));
            throw $e;
        }
        $store->setMeta('last_fetch_status', 'ok');
        $store->setMeta('last_fetch_message', $result['fetched'] . ' new, ' . $result['updated'] . ' updated');

        return $result;
    }

    // ---------------------------------------------------------------- scheduler / widget

    public function tasks(): array
    {
        // Runs hourly, the callable honours the "fetch interval" setting itself.
        return ['fetch' => ['label' => 'UXS_REVIEW_AGGREGATOR_JOB_FETCH', 'callable' => [$this, 'fetchJob'], 'interval' => 'PT1H']];
    }

    public function fetchJob(): string
    {
        $ctx = $this->ctx();
        if (!$ctx->setting('auto_fetch', true) || (int) $ctx->setting('central_profile_id', 0) <= 0) {
            return 'skipped (auto-fetch off or no profile)';
        }
        if (!CentralSource::available($ctx)) {
            return 'skipped (site is not connected to the central app)';
        }
        $hours = self::INTERVAL_HOURS[(string) $ctx->setting('fetch_interval', '24h')] ?? 24;
        $last  = strtotime($this->store()->getMeta('last_fetch_at') . ' UTC');
        if ($last && time() - $last < $hours * 3600 - 300) {
            return 'skipped (fetched recently)';
        }
        try {
            $res = $this->fetch(true);
        } catch (ApiException $e) {
            // Logged in the module meta (last_fetch_*), shown in the admin. Returned as text, not thrown: a failing CA must not
            // make the Joomla scheduler e-mail the administrators on every run.
            return 'error: ' . $e->getMessage();
        }

        return $res['fetched'] . ' new, ' . $res['updated'] . ' updated';
    }

    public function dashboardWidget(): ?array
    {
        try {
            $agg = $this->store()->aggregate();
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => $this->ctx()->t('UXS_REVIEW_AGGREGATOR_NAME'),
            'value'  => $agg['count'] > 0 ? number_format($agg['average'], 1) . ' / 5' : '-',
            'text'   => \Joomla\CMS\Language\Text::plural('UXS_REVIEW_AGGREGATOR_WIDGET_TEXT', $agg['count']),
            'status' => $agg['count'] === 0 ? 'warn' : ($agg['average'] >= 4 ? 'ok' : 'warn'),
        ];
    }

    // ---------------------------------------------------------------- migrations

    public function migrations(): array
    {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_reviews` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`source` VARCHAR(32) NOT NULL DEFAULT \'\', '
                . '`origin` VARCHAR(16) NOT NULL DEFAULT \'ca\', '
                . '`external_id` VARCHAR(191) NOT NULL DEFAULT \'\', '
                . '`dedup_hash` VARCHAR(64) NOT NULL, '
                . '`author` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`rating` TINYINT UNSIGNED NOT NULL DEFAULT 0, '
                . '`text` MEDIUMTEXT NULL, '
                . '`review_date` DATETIME NULL, '
                . '`imported_at` DATETIME NOT NULL, '
                . '`visible` TINYINT(1) NOT NULL DEFAULT 1, '
                . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_dedup` (`dedup_hash`), KEY `idx_source` (`source`), KEY `idx_visible_date` (`visible`, `review_date`)'
                . ')' . $tail,
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_reviews_meta` ('
                . '`name` VARCHAR(64) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`name`)'
                . ')' . $tail,
            ],
        ];
    }

    // ---------------------------------------------------------------- front end

    /**
     * Rendered reviews for mod_uxstudio_reviews and the content tag. $o overrides the module settings; empty / null
     * values mean "use the setting": layout, count, min_rating, order, source, schema (null|bool), heading, css_class.
     *
     * @param array<string,mixed> $o
     */
    public function renderFront(array $o = []): string
    {
        $ctx   = $this->ctx();
        $pick  = static fn (string $key, mixed $setting): mixed => ($o[$key] ?? '') === '' || $o[$key] === null ? $setting : $o[$key];

        $layout = (string) $pick('layout', $ctx->setting('layout', 'grid'));
        $order  = (string) $pick('order', $ctx->setting('order_by', 'newest'));
        $source = ReviewStore::normalizeSource((string) ($o['source'] ?? ''));
        $count  = max(1, min(100, (int) $pick('count', $ctx->setting('per_page', 10))));
        $minR   = max(0, min(5, (int) $pick('min_rating', $ctx->setting('min_rating', 1))));
        $schema = isset($o['schema']) && $o['schema'] !== '' ? filter_var($o['schema'], FILTER_VALIDATE_BOOLEAN) : (bool) $ctx->setting('schema_enabled', true);

        $store  = $this->store();
        $name   = trim((string) $ctx->setting('schema_name', '')) ?: (string) $ctx->app()->get('sitename', '');
        $html   = Renderer::render(
            $layout === 'badge' ? [] : $store->visible(['source' => $source, 'min_rating' => $minR, 'order' => $order, 'limit' => $count]),
            $store->aggregate($source),
            [
                'layout'         => $layout,
                'show_summary'   => (bool) $ctx->setting('show_summary', true),
                'show_platform'  => (bool) $ctx->setting('show_platform', true),
                'show_date'      => (bool) $ctx->setting('show_date', true),
                'show_avatar'    => (bool) $ctx->setting('show_avatar', true),
                'show_stars'     => (bool) $ctx->setting('show_stars', true),
                'text_limit'     => (int) $ctx->setting('text_limit', 200),
                'schema'         => $schema,
                'schema_type'    => (string) $ctx->setting('schema_type', 'Organization'),
                'schema_name'    => $name,
                'schema_image'   => $this->absoluteUrl((string) $ctx->setting('schema_image', '')),
                'schema_reviews' => (bool) $ctx->setting('schema_reviews', false),
                'heading'        => (string) ($o['heading'] ?? ''),
                'css_class'      => (string) ($o['css_class'] ?? ''),
            ]
        );

        $ctx->useStyle('reviews.css');
        $ctx->useScript('reviews.js');

        return $html;
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
    }

    /** onContentPrepare: replaces {uxstudio_reviews key=value ...} in the text of an article / custom module. */
    public function contentPrepare(Event $event): void
    {
        $item = $event->hasArgument('subject') ? $event->getArgument('subject') : $event->getArgument(1);
        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !str_contains($item->text, '{uxstudio_reviews')) {
            return;
        }

        $item->text = preg_replace_callback(
            '/\{uxstudio_reviews([^}]*)\}/',
            function (array $m): string {
                $o = [];
                if (preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $m[1], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $key = strtolower($p[1]);
                        $val = ($p[4] ?? '') !== '' ? $p[4] : (($p[3] ?? '') !== '' ? $p[3] : ($p[2] ?? ''));
                        if ($key === 'platform') {
                            $key = 'source';
                        }
                        if (\in_array($key, ['layout', 'count', 'min_rating', 'order', 'source', 'schema', 'heading'], true)) {
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

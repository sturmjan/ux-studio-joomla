<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Pulls reviews from UX1 Network app (CA) `reviews_api` through the CaConnector's CaClient. CaClient may be missing (module
 * not installed) or return null (site not paired / connector off): then the aggregator simply works from manual input.
 */
final class CentralSource
{
    private const CLIENT_CLASS = '\\Ux1\\UxStudio\\Modules\\CaConnector\\CaClient';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 20;

    /** CaClient or null (a loose type on purpose: the class belongs to another module and may not exist). */
    public static function client(ModuleContext $ctx): ?object
    {
        $class = self::CLIENT_CLASS;
        if (!class_exists($class) || !method_exists($class, 'fromContext')) {
            return null;
        }

        try {
            $client = $class::fromContext($ctx);
        } catch (\Throwable $e) {
            return null;
        }

        return \is_object($client) ? $client : null;
    }

    public static function available(ModuleContext $ctx): bool
    {
        return self::client($ctx) !== null;
    }

    /**
     * Reads all stored reviews of one CA profile (paged). With $etag (from the previous full read) the first request is
     * conditional: HTTP 304 means nothing changed and nothing else is downloaded.
     *
     * @return array{items:array<int,array<string,mixed>>,etag:string,not_modified:bool}
     * @throws ApiException when the site is not connected or CA answers with an error
     */
    public static function fetchAll(ModuleContext $ctx, ReviewStore $store, int $profileId, string $etag = ''): array
    {
        $client = self::requireClient($ctx, $store);
        $items  = [];
        $newTag = '';
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $res = self::call($client, 'reviews', ['profile_id' => $profileId, 'limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE], $page === 0 ? $etag : '');
            if (!empty($res['not_modified'])) {
                return ['items' => [], 'etag' => $etag, 'not_modified' => true];
            }
            $newTag = $page === 0 ? (string) ($res['etag'] ?? '') : $newTag;
            $batch  = isset($res['reviews']) && \is_array($res['reviews']) ? array_values(array_filter($res['reviews'], 'is_array')) : [];
            $items  = array_merge($items, $batch);
            if (\count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return ['items' => $items, 'etag' => $newTag, 'not_modified' => false];
    }

    /**
     * Asks CA to queue a full Google Maps scan and find/create the profile of this site.
     *
     * @return array<string,mixed> {profile_id, job_id, status}
     * @throws ApiException
     */
    public static function requestScrape(ModuleContext $ctx, ReviewStore $store, string $placeId, string $mapsUrl, string $name): array
    {
        $client = self::requireClient($ctx, $store);
        $res    = self::call($client, 'request_scrape', ['source' => 'google_maps', 'name' => $name, 'external_id' => $placeId, 'maps_url' => $mapsUrl]);

        return [
            'profile_id' => (int) ($res['profile_id'] ?? 0),
            'job_id'     => (int) ($res['job_id'] ?? 0),
            'status'     => preg_replace('/[^a-z_]/', '', strtolower((string) ($res['status'] ?? 'queued'))) ?: 'queued',
        ];
    }

    /** Splits a Maps URL / Place ID into [placeId, mapsUrl]. @return array{0:string,1:string} */
    public static function resolvePlace(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['', ''];
        }
        if (preg_match('/^ChIJ[A-Za-z0-9_-]{20,}$/', $input)) {
            return [$input, ''];
        }
        if (preg_match('/place_id[:=]([A-Za-z0-9_-]{20,})/', $input, $m)) {
            return [$m[1], $input];
        }
        if (preg_match('#^https?://#i', $input)) {
            return ['', $input];
        }

        return ['', ''];
    }

    private static function requireClient(ModuleContext $ctx, ReviewStore $store): CentralReviewsClient
    {
        $client = CentralReviewsClient::create($ctx, $store);
        if ($client === null) {
            throw new ApiException($ctx->t('UXS_REVIEW_AGGREGATOR_ERR_NO_CA'), 424, 'ca_unavailable');
        }

        return $client;
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private static function call(CentralReviewsClient $client, string $action, array $query, string $etag = ''): array
    {
        try {
            return $client->call($action, $query, $etag);
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage(), 502, 'ca_error');
        }
    }
}

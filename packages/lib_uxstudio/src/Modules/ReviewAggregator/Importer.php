<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Ux1\UxStudio\Core\ApiException;

\defined('_JEXEC') or die;

/**
 * Parses a pasted / uploaded CSV or JSON list of reviews (the fallback when the site is not paired with the central app).
 * Returns raw items; ReviewStore::normalize() cleans them.
 */
final class Importer
{
    public const MAX_BYTES = 1048576;

    public const MAX_ROWS = 2000;

    /** Column header aliases => canonical key. */
    private const ALIASES = [
        'source' => 'source', 'platform' => 'source', 'site' => 'source',
        'author' => 'author', 'author_name' => 'author', 'name' => 'author', 'reviewer' => 'author',
        'rating' => 'rating', 'stars' => 'rating', 'score' => 'rating',
        'text' => 'text', 'review' => 'text', 'review_text' => 'text', 'comment' => 'text', 'content' => 'text',
        'date' => 'review_date', 'review_date' => 'review_date', 'created' => 'review_date',
        'external_id' => 'external_id', 'id' => 'external_id',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function parse(string $format, string $data): array
    {
        if (\strlen($data) > self::MAX_BYTES) {
            throw new ApiException('The import is too large (max 1 MB).', 413, 'too_large');
        }
        $data = preg_replace('/^\xEF\xBB\xBF/', '', $data) ?? $data;
        if (trim($data) === '') {
            throw new ApiException('Nothing to import.', 422, 'empty');
        }

        $rows = $format === 'json' ? self::fromJson($data) : self::fromCsv($data);
        if (\count($rows) > self::MAX_ROWS) {
            throw new ApiException('Too many reviews in one import (max ' . self::MAX_ROWS . ').', 413, 'too_many');
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private static function fromJson(string $data): array
    {
        $decoded = json_decode($data, true);
        if (!\is_array($decoded)) {
            throw new ApiException('Invalid JSON.', 422, 'invalid_json');
        }
        if (isset($decoded['reviews']) && \is_array($decoded['reviews'])) {
            $decoded = $decoded['reviews'];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return array<int,array<string,mixed>> */
    private static function fromCsv(string $data): array
    {
        $firstLine = strtok($data, "\n") ?: '';
        $delimiter = ',';
        $best      = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $n = substr_count($firstLine, $candidate);
            if ($n > $best) {
                $best      = $n;
                $delimiter = $candidate;
            }
        }

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new ApiException('Cannot read the import.', 500, 'io');
        }
        fwrite($fh, $data);
        rewind($fh);

        $header = null;
        $rows   = [];
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || $cells === []) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn ($c): string => self::ALIASES[strtolower(trim((string) $c))] ?? '', $cells);
                if (!\in_array('rating', $header, true)) {
                    fclose($fh);
                    throw new ApiException('The first CSV row must be a header with at least a "rating" column.', 422, 'invalid_csv');
                }
                continue;
            }
            $item = [];
            foreach ($header as $i => $key) {
                if ($key !== '' && isset($cells[$i])) {
                    $item[$key] = $cells[$i];
                }
            }
            $rows[] = $item;
        }
        fclose($fh);

        return $rows;
    }
}

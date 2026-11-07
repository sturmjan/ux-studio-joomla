<?php

namespace Ux1\UxStudio\Modules\Redirects;

\defined('_JEXEC') or die;

/**
 * CSV import/export and a one-click import from Joomla's own Redirects component (com_redirect). Every row goes through
 * Repository::save(), so imported rules get the same chain/loop checks.
 */
final class Importer
{
    /** Max rows per import call. */
    public const MAX_ROWS = 5000;

    /** Max CSV size accepted (bytes). */
    public const MAX_CSV = 2097152;

    private const COLUMN_ALIASES = ['url' => 'source', 'from' => 'source', 'old' => 'source', 'to' => 'target', 'new' => 'target', 'status' => 'code'];

    /**
     * Imports CSV text. Columns: source, target, code, note (header optional, comma or semicolon separated). Empty
     * target + code 410 = gone. The export adds match_type, enabled, hits, position, case_sensitive, query_mode, last_hit.
     *
     * @return array{summary:array<string,int>,results:array<int,array<string,mixed>>}
     */
    public static function csv(string $csv, string $onConflict, bool $dryRun): array
    {
        $csv   = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        $first = strtok($csv, "\r\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        // fgetcsv (not a split by lines) keeps a quoted cell with a line break in one piece.
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $columns = ['source', 'target', 'code', 'note'];
        $items   = [];
        $n       = -1;
        while (($row = fgetcsv($handle, 0, $delim, '"', '')) !== false) {
            $n++;
            if ($row === [null]) {
                continue; // blank line
            }
            $cells = array_map(static fn (?string $c): string => trim((string) $c), $row);
            if ($n === 0 && \in_array(strtolower($cells[0] ?? ''), ['source', 'url', 'from', 'old'], true)) {
                $columns = array_map(static fn (string $c): string => self::COLUMN_ALIASES[strtolower($c)] ?? strtolower($c), $cells);
                continue;
            }
            $item = [];
            foreach ($columns as $i => $column) {
                if (isset($cells[$i])) {
                    // Undo the formula guard our own export adds (only in front of a character it guards against).
                    $item[$column] = preg_match("/^'[=+\-@\t\r]/", $cells[$i]) === 1 ? substr($cells[$i], 1) : $cells[$i];
                }
            }
            if (empty($item['code'])) {
                // No code and no target is not a hidden 410: say it with the code 410 or give a target.
                $item['code'] = ($item['target'] ?? '') === '' ? 0 : 301;
            }
            $items[] = $item;
        }
        fclose($handle);

        return self::run($items, $onConflict, $dryRun);
    }

    /** Whether the Joomla Redirects component has links that could be imported (rows with a new URL). */
    public static function joomlaAvailable(): int
    {
        $db = Repository::db();
        try {
            if (!\in_array($db->replacePrefix('#__redirect_links'), $db->getTableList(), true)) {
                return 0;
            }

            return (int) $db->setQuery(
                $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__redirect_links'))
                    ->where($db->quoteName('new_url') . " <> ''")->where($db->quoteName('published') . ' IN (0, 1)')
            )->loadResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Imports the redirects of com_redirect (links with a new URL; the collected 404 addresses without a target are
     * not rules). Disabled links stay disabled, trashed ones are skipped.
     *
     * @return array{summary:array<string,int>,results:array<int,array<string,mixed>>}
     */
    public static function joomla(string $onConflict, bool $dryRun): array
    {
        if (self::joomlaAvailable() === 0) {
            return ['summary' => [], 'results' => []];
        }
        $db   = Repository::db();
        $rows = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__redirect_links'))
                ->where($db->quoteName('new_url') . " <> ''")->where($db->quoteName('published') . ' IN (0, 1)')
                ->order($db->quoteName('id') . ' ASC'),
            0,
            self::MAX_ROWS
        )->loadAssocList() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $code = (int) ($row['header'] ?? 301);
            $items[] = [
                'source'   => (string) $row['old_url'],
                'target'   => (string) $row['new_url'],
                'code'     => \in_array($code, Repository::CODES, true) && $code !== 410 ? $code : 301,
                'enabled'  => (int) $row['published'] === 1,
                'note'     => (string) ($row['comment'] ?? ''),
                'hits'     => (int) ($row['hits'] ?? 0),
                'last_hit' => (string) ($row['modified_date'] ?? ''),
            ];
        }

        return self::run($items, $onConflict, $dryRun);
    }

    /** All rules as CSV text (same columns the importer reads). */
    public static function exportCsv(): string
    {
        $db   = Repository::db();
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(Repository::TABLE))->order($db->quoteName('id') . ' ASC'))->loadAssocList() ?: [];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['source', 'target', 'code', 'note', 'match_type', 'enabled', 'hits', 'position', 'case_sensitive', 'query_mode', 'last_hit'], ',', '"', '');
        foreach ($rows as $row) {
            $mode = ((string) ($row['query_mode'] ?? '')) === '' ? 'default' : (string) $row['query_mode'];
            fputcsv(
                $out,
                self::safeRow([$row['source'], (int) $row['code'] === 410 ? '' : $row['target'], $row['code'], $row['note'], $row['match_type'], $row['enabled'], $row['hits'], $row['position'], (int) ($row['case_sensitive'] ?? 0), $mode, (string) ($row['last_hit'] ?? '')]),
                ',',
                '"',
                ''
            );
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Guards against CSV formula injection when the file is opened in a spreadsheet (the importer undoes it).
     *
     * @param array<int,mixed> $cells
     * @return array<int,string>
     */
    private static function safeRow(array $cells): array
    {
        return array_map(static function (mixed $cell): string {
            $cell = (string) $cell;

            return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
        }, $cells);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{summary:array<string,int>,results:array<int,array<string,mixed>>}
     */
    private static function run(array $items, string $onConflict, bool $dryRun): array
    {
        $summary = [];
        $results = [];
        foreach (\array_slice($items, 0, self::MAX_ROWS) as $index => $item) {
            $item['origin'] = 'import';
            $result         = Repository::save($item, $onConflict, $dryRun);
            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
            // Keep the response small: only rows worth looking at.
            if ($result['errors'] || $result['warnings']) {
                $result['index'] = $index;
                $results[]       = $result;
            }
        }

        return ['summary' => $summary, 'results' => \array_slice($results, 0, 200)];
    }
}

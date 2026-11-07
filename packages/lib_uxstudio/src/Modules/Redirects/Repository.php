<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\SafeRegex;

\defined('_JEXEC') or die;

/**
 * Redirect rule store. Every write path (admin UI, CSV / Joomla import, slug monitor, the one-click 404 suggestion)
 * goes through save(), which enforces the SEO rules: no self-redirects, no loops, chains flattened to a single hop,
 * same-host targets stored relative.
 */
final class Repository
{
    public const CODES = [301, 302, 307, 308, 410];

    public const MATCH_TYPES = ['exact', 'regex'];

    public const ORIGINS = ['manual', 'import', 'auto', 'suggest'];

    /**
     * How a rule treats the query string. `default`: a source without a query matches any query (the module setting
     * decides whether it is passed on), a source with a query matches only that query. `exact` also refuses a request
     * that carries any query when the source has none; `ignore` matches the path and drops the visitor's query;
     * `pass` matches the path and always appends the visitor's query to the target.
     */
    public const QUERY_MODES = ['default', 'exact', 'ignore', 'pass'];

    /** Max hops followed when flattening a chain. */
    private const MAX_HOPS = 10;

    /** Max items in one bulk call. */
    public const BULK_MAX = 500;

    public const TABLE = '#__uxstudio_redirects';

    public static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    public static function now(): string
    {
        return Factory::getDate()->toSql();
    }

    /*
     * ------------------------------------------------------------------
     * Matching (front end + test tool)
     * ------------------------------------------------------------------
     */

    /**
     * Finds the rule for a normalised path + query. Exact rules win over regex; an exact rule WITH a query beats the
     * same path without one.
     *
     * @return array{rule:array<string,mixed>,target:string}|null rule row + resolved absolute target ('' for 410)
     */
    public static function match(string $path, string $query, ?string $pathCs = null): ?array
    {
        $db     = self::db();
        $hashes = [Url::hash('exact', $path)];
        if ($query !== '') {
            $hashes[] = Url::hash('exact', Url::key($path, $query));
        }
        // One indexed query: the exact candidates plus every enabled regex rule (few, they are scanned in PHP).
        $q = $db->createQuery()
            ->select($db->quoteName(['id', 'source', 'source_key', 'source_cs', 'case_sensitive', 'query_mode', 'match_type', 'target', 'code', 'position']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('enabled') . ' = 1');
        $in = [];
        foreach ($hashes as $i => $hash) {
            $name = ':h' . $i;
            $q->bind($name, $hashes[$i]);
            $in[] = $name;
        }
        $q->where('((' . $db->quoteName('match_type') . ' = ' . $db->quote('exact') . ' AND ' . $db->quoteName('source_hash') . ' IN (' . implode(',', $in) . ')) OR '
            . $db->quoteName('match_type') . ' = ' . $db->quote('regex') . ')')
            ->order($db->quoteName('position') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        $rows = $db->setQuery($q)->loadAssocList() ?: [];

        $exact = [];
        $regex = [];
        foreach ($rows as $row) {
            if ($row['match_type'] === 'regex') {
                $regex[] = $row;
            } elseif (self::applies($row, $path, $query, $pathCs)) {
                $exact[] = $row;
            }
        }
        if ($exact) {
            usort($exact, static fn (array $a, array $b): int => \strlen((string) $b['source_key']) <=> \strlen((string) $a['source_key']));

            return ['rule' => $exact[0], 'target' => self::resolveTarget($exact[0])];
        }

        $subject = Url::key($path, $query);
        foreach ($regex as $rule) {
            $matches   = [];
            $sensitive = !empty($rule['case_sensitive']);
            // A case sensitive regex rule sees the path as the visitor typed it (and has no `i` flag).
            $text = $sensitive ? Url::key($pathCs ?? $path, $query) : $subject;
            if (SafeRegex::match(SafeRegex::delimit((string) $rule['source_key'], $sensitive), $text, $matches)) {
                $target = self::resolveTarget($rule, $matches);
                if ($target === '' && (int) $rule['code'] !== 410) {
                    continue; // the captures do not fit the target (foreign host): this rule does not apply
                }

                return ['rule' => $rule, 'target' => $target];
            }
        }

        return null;
    }

    /** Whether a rule whose source hash matched really applies to this request (per-rule case and query mode). */
    public static function applies(array $row, string $path, string $query, ?string $pathCs): bool
    {
        $hasQuery = str_contains((string) $row['source_key'], '?');
        if ((string) ($row['query_mode'] ?? '') === 'exact' && !$hasQuery && $query !== '') {
            return false;
        }
        $sourceCs = (string) ($row['source_cs'] ?? '');
        if (!empty($row['case_sensitive']) && $sourceCs !== '' && ($row['match_type'] ?? 'exact') === 'exact') {
            return Url::key($pathCs ?? $path, $hasQuery ? $query : '') === $sourceCs;
        }

        return true;
    }

    /**
     * First enabled (or any) exact rule for a path + query, used by the chain checks.
     *
     * @return array<string,mixed>|null
     */
    public static function findExact(string $path, string $query, bool $enabledOnly = true, ?string $pathCs = null): ?array
    {
        $db     = self::db();
        $hashes = [Url::hash('exact', $path)];
        if ($query !== '') {
            $hashes[] = Url::hash('exact', Url::key($path, $query));
        }
        $q = $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('match_type') . ' = ' . $db->quote('exact'))
            ->whereIn($db->quoteName('source_hash'), $hashes, ParameterType::STRING)
            ->order('CHAR_LENGTH(' . $db->quoteName('source_key') . ') DESC, ' . $db->quoteName('position') . ' ASC, ' . $db->quoteName('id') . ' ASC');
        if ($enabledOnly) {
            $q->where($db->quoteName('enabled') . ' = 1');
        }
        foreach ($db->setQuery($q, 0, 20)->loadAssocList() ?: [] as $row) {
            if (self::applies($row, $path, $query, $pathCs)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Absolute URL a rule sends the visitor to ('' for 410). `$1`..`$9` in the target of a regex rule are replaced by
     * the captured groups.
     *
     * @param array<int|string,mixed> $matches
     */
    public static function resolveTarget(array $rule, array $matches = []): string
    {
        if ((int) $rule['code'] === 410) {
            return '';
        }
        $target = (string) $rule['target'];
        if ($matches && str_contains($target, '$')) {
            $template = $target;
            // The captures come from the decoded, lowercased match key: encode them again per segment, so a capture can
            // neither start a fragment ("%23"), smuggle a space or "@" into the address nor break the header.
            $target = (string) preg_replace_callback(
                '~\$(\d)~',
                static fn (array $m): string => self::encodeCapture((string) ($matches[(int) $m[1]] ?? '')),
                $target
            );
            // A capture must never turn a relative target into "//evil.tld" or inject a header line.
            $target = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $target);
            if (str_starts_with($template, '/')) {
                $target = '/' . ltrim($target, '/\\');
            } elseif (!self::sameAuthority($template, $target)) {
                // An absolute target keeps the host the rule was saved with, whatever the visitor typed.
                return '';
            }
        }

        return Url::absolute($target);
    }

    /** Percent-encodes a captured group per path segment; "/", "?", "=" and "&" stay so paths and queries pass through. */
    private static function encodeCapture(string $value): string
    {
        return strtr(rawurlencode($value), ['%2F' => '/', '%3F' => '?', '%3D' => '=', '%26' => '&']);
    }

    /** Whether the host of the resolved URL is the host of the rule's template (a "$1" in the host matches host characters only). */
    private static function sameAuthority(string $template, string $resolved): bool
    {
        if (!preg_match('#^(https?://)([^/?\#]*)#i', $template, $t) || !preg_match('#^(https?://)([^/?\#]*)#i', $resolved, $r)) {
            return false;
        }
        if (strcasecmp($t[1], $r[1]) !== 0) {
            return false;
        }
        // A "$n" at the END of the host would let the visitor append a domain ("example.com" + ".evil.test"): there it must
        // add nothing (the capture has to start with "/"). In the middle of the host a capture only picks a label.
        $quoted  = preg_replace('~(?:\\\\\$\d)+$~', '', preg_quote(strtolower($t[2]), '~'));
        $pattern = preg_replace('~\\\\\$\d~', '[a-z0-9.\-]*', (string) $quoted);

        return (bool) preg_match('~^' . $pattern . '$~', strtolower($r[2]));
    }

    /** Counts a hit. */
    public static function recordHit(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $db  = self::db();
        $now = self::now();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('hits') . ' = ' . $db->quoteName('hits') . ' + 1')
                ->set($db->quoteName('last_hit') . ' = :now')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':now', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Zeroes the hit counter of some rules, or of all of them (null).
     *
     * @param int[]|null $ids
     */
    public static function resetHits(?array $ids = null): int
    {
        $db = self::db();
        $q  = $db->createQuery()->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('hits') . ' = 0')->set($db->quoteName('last_hit') . ' = NULL');
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) {
                return 0;
            }
            $q->whereIn($db->quoteName('id'), $ids);
        }
        $db->setQuery($q)->execute();

        return $db->getAffectedRows();
    }

    /*
     * ------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------
     */

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        $db  = self::db();
        $row = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /**
     * Paged, filtered list of rules.
     *
     * @param array<string,mixed> $args search, code, origin, match_type, enabled, orderby, order, limit, offset
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function list(array $args): array
    {
        $db    = self::db();
        $where = [];
        $bind  = [];

        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $bind['like'] = '%' . $db->escape($search, true) . '%';
            $where[]      = '(' . $db->quoteName('source') . ' LIKE :like OR ' . $db->quoteName('target') . ' LIKE :like2 OR ' . $db->quoteName('note') . ' LIKE :like3)';
            $bind['like2'] = $bind['like'];
            $bind['like3'] = $bind['like'];
        }
        if (!empty($args['code']) && \in_array((int) $args['code'], self::CODES, true)) {
            $where[]      = $db->quoteName('code') . ' = ' . (int) $args['code'];
        }
        if (!empty($args['origin']) && \in_array($args['origin'], self::ORIGINS, true)) {
            $where[] = $db->quoteName('origin') . ' = ' . $db->quote((string) $args['origin']);
        }
        if (!empty($args['match_type']) && \in_array($args['match_type'], self::MATCH_TYPES, true)) {
            $where[] = $db->quoteName('match_type') . ' = ' . $db->quote((string) $args['match_type']);
        }
        if (isset($args['enabled']) && $args['enabled'] !== '' && $args['enabled'] !== null) {
            $where[] = $db->quoteName('enabled') . ' = ' . (filter_var($args['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        $orderby = \in_array($args['orderby'] ?? '', ['id', 'source', 'hits', 'last_hit', 'created_at', 'updated_at', 'code'], true) ? (string) $args['orderby'] : 'id';
        $order   = strtolower((string) ($args['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
        $limit   = max(1, min(self::BULK_MAX, (int) ($args['limit'] ?? 50)));
        $offset  = max(0, (int) ($args['offset'] ?? 0));

        $apply = static function ($q) use ($where, $bind) {
            foreach ($where as $clause) {
                $q->where($clause);
            }
            foreach ($bind as $name => $value) {
                $q->bind(':' . $name, $bind[$name]);
            }

            return $q;
        };

        $total = (int) $db->setQuery($apply($db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE))))->loadResult();
        $rows  = $db->setQuery(
            $apply($db->createQuery()->select('*')->from($db->quoteName(self::TABLE)))
                ->order($db->quoteName($orderby) . ' ' . $order . ', ' . $db->quoteName('id') . ' DESC'),
            $offset,
            $limit
        )->loadAssocList() ?: [];

        return ['items' => array_map([self::class, 'format'], $rows), 'total' => $total];
    }

    /**
     * Public shape of a rule (API, export).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function format(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'source'         => (string) $row['source'],
            'match_type'     => (string) $row['match_type'],
            'target'         => (string) $row['target'],
            'target_url'     => (int) $row['code'] === 410 ? '' : Url::absolute((string) $row['target']),
            'code'           => (int) $row['code'],
            'case_sensitive' => (bool) (int) ($row['case_sensitive'] ?? 0),
            'query_mode'     => ((string) ($row['query_mode'] ?? '')) === '' ? 'default' : (string) $row['query_mode'],
            'position'       => (int) ($row['position'] ?? 0),
            'enabled'        => (bool) (int) $row['enabled'],
            'hits'           => (int) $row['hits'],
            'last_hit'       => $row['last_hit'],
            'origin'         => (string) $row['origin'],
            'note'           => (string) $row['note'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    /*
     * ------------------------------------------------------------------
     * Writes
     * ------------------------------------------------------------------
     */

    /**
     * Validates and creates or updates one rule.
     *
     * Input: source, match_type (exact|regex), target, code (301|302|307|308|410), enabled, note, origin, position,
     * case_sensitive, query_mode, id (update by id, missing fields keep their values).
     *
     * @param array<string,mixed> $input
     * @param string              $onConflict update|skip|error when the source already has a rule
     * @return array{status:string,id:int|null,source:string,target_url:string,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>}
     */
    public static function save(array $input, string $onConflict = 'update', bool $dryRun = false): array
    {
        $errors   = [];
        $warnings = [];
        $id       = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? self::get($id) : null;

        if ($id > 0 && !$existing) {
            return self::result('error', null, '', '', [self::issue('not_found', 'UXS_REDIRECTS_E_NOT_FOUND')], []);
        }

        // Partial update by id: missing fields keep their current values.
        if ($existing) {
            $input += [
                'source'         => $existing['source'],
                'match_type'     => $existing['match_type'],
                'code'           => $existing['code'],
                'enabled'        => $existing['enabled'],
                'note'           => $existing['note'],
                'origin'         => $existing['origin'],
                'case_sensitive' => $existing['case_sensitive'] ?? 0,
                'query_mode'     => ((string) ($existing['query_mode'] ?? '')) === '' ? 'default' : $existing['query_mode'],
            ];
            if (!\array_key_exists('target', $input)) {
                $input['target'] = $existing['target'];
            }
        }

        $matchType = (string) ($input['match_type'] ?? 'exact');
        $code      = (int) ($input['code'] ?? 301);
        $source    = trim((string) ($input['source'] ?? ''));
        $origin    = \in_array($input['origin'] ?? '', self::ORIGINS, true) ? (string) $input['origin'] : 'manual';

        if (!\in_array($matchType, self::MATCH_TYPES, true)) {
            $errors[] = self::issue('invalid_match_type', 'UXS_REDIRECTS_E_MATCH_TYPE');
        }
        if (!\in_array($code, self::CODES, true)) {
            $errors[] = self::issue('invalid_code', 'UXS_REDIRECTS_E_CODE');
        }

        // --- Per-rule match options ---------------------------------------
        $caseSensitive = filter_var($input['case_sensitive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $queryMode     = strtolower(trim((string) ($input['query_mode'] ?? 'default')));
        $queryMode     = $queryMode === '' ? 'default' : $queryMode;
        if (!\in_array($queryMode, self::QUERY_MODES, true)) {
            $errors[]  = self::issue('invalid_query_mode', 'UXS_REDIRECTS_E_QUERY_MODE');
            $queryMode = 'default';
        }
        if ($matchType === 'regex' && $queryMode !== 'default') {
            // A regular expression sees path and query as one text; there is no separate query handling.
            $warnings[] = self::issue('query_mode_regex', 'UXS_REDIRECTS_W_QUERY_REGEX');
            $queryMode  = 'default';
        }

        // --- Source ---------------------------------------------------------
        $sourceKey  = '';
        $sourcePath = '';
        $sourceCs   = '';
        $parsedCs   = '';
        if ($source === '') {
            $errors[] = self::issue('source_required', 'UXS_REDIRECTS_E_SOURCE_REQUIRED');
        } elseif (\strlen($source) > 1900) {
            $errors[] = self::issue('invalid_source', 'UXS_REDIRECTS_E_SOURCE_INVALID');
        } elseif ($matchType === 'regex') {
            if (\strlen($source) > SafeRegex::MAX_PATTERN || !SafeRegex::compiles(SafeRegex::delimit($source))) {
                $errors[] = self::issue('invalid_regex', 'UXS_REDIRECTS_E_REGEX_INVALID');
            } elseif (!SafeRegex::probe(SafeRegex::delimit($source))) {
                $errors[] = self::issue('unsafe_regex', 'UXS_REDIRECTS_E_REGEX_UNSAFE');
            }
            $sourceKey = $source;
        } else {
            // A full address of THIS site is shown (and exported) as a site-relative path.
            $parts = Url::split($source);
            if ($parts !== null && $parts['host'] !== '' && $parts['host'] === Url::homeHost()) {
                $source = Url::toRelative($source);
            }
            $parsed     = Url::parseSource($source);
            $sourcePath = $parsed['path'];
            $sourceKey  = Url::key($parsed['path'], $parsed['query']);
            $sourceCs   = $caseSensitive ? Url::key($parsed['path_cs'], $parsed['query']) : '';
            $parsedCs   = Url::key($parsed['path_cs'], $parsed['query']);
            if ($parsed['query'] !== '' && \in_array($queryMode, ['ignore', 'pass'], true)) {
                $errors[] = self::issue('query_mode_source_query', 'UXS_REDIRECTS_E_QUERY_SOURCE');
            }
            if ($sourcePath === '' || ($parsed['path'] === '' && $parsed['query'] === '')) {
                $errors[] = self::issue('invalid_source', 'UXS_REDIRECTS_E_SOURCE_INVALID');
            } elseif ($sourceKey === '/') {
                $errors[] = self::issue('source_is_home', 'UXS_REDIRECTS_E_SOURCE_HOME');
            } elseif (Url::isReserved($sourcePath)) {
                $errors[] = self::issue('source_reserved', 'UXS_REDIRECTS_E_SOURCE_RESERVED');
            }
        }
        $sourceHash = Url::hash($matchType, $sourceKey);

        // --- Target ---------------------------------------------------------
        $target = trim((string) ($input['target'] ?? ''));
        if ($code === 410) {
            $target = '';
        } elseif ($target === '') {
            $errors[] = self::issue('target_required', 'UXS_REDIRECTS_E_TARGET_REQUIRED');
        } elseif (\strlen($target) > 1900 || preg_match('/[\x00-\x1f\x7f]/', $target)) {
            $errors[] = self::issue('invalid_target', 'UXS_REDIRECTS_E_TARGET_INVALID');
        } elseif (str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            $errors[] = self::issue('invalid_target', 'UXS_REDIRECTS_E_TARGET_PROTOCOL_RELATIVE');
        } elseif (Url::isRoute($target)) {
            // A Joomla route such as index.php?Itemid=101; resolved through the site router when the rule fires.
        } elseif ($target[0] !== '/') {
            $parts = Url::split($target);
            if ($parts === null || !preg_match('#^https?://#i', $target) || $parts['host'] === '') {
                $errors[] = self::issue('invalid_target', 'UXS_REDIRECTS_E_TARGET_INVALID');
            } elseif (Url::isInternal($target)) {
                $target = Url::toRelative($target);
            }
        }

        $isTemplate = $matchType === 'regex' && str_contains($target, '$');

        // --- Chains & loops (exact internal targets) -------------------------
        if (!$errors && $target !== '' && !$isTemplate && Url::targetKey($target) !== '') {
            // A case sensitive rule may legitimately send /Foo to /foo, so its loop check compares the letters as typed.
            $csMode  = $caseSensitive && $matchType === 'exact' && $sourceCs !== '';
            $visited = [($csMode ? $sourceCs : $sourceKey) => true];
            for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
                $tkeyLc = Url::targetKey($target);
                if ($tkeyLc === '') {
                    break;
                }
                $tkeyCs = Url::targetKey($target, true);
                $tkey   = $csMode ? $tkeyCs : $tkeyLc;
                // The rule would match its own target: a query-less source matches any query (unless its query mode
                // is "exact"), a regex may match the target path.
                $selfMatch = $matchType === 'regex'
                    ? SafeRegex::match(SafeRegex::delimit($sourceKey), $tkey)
                    : (!str_contains($sourceKey, '?') && !($queryMode === 'exact' && str_contains($tkey, '?')) && explode('?', $tkey, 2)[0] === ($csMode ? $sourceCs : $sourceKey));
                if (isset($visited[$tkey]) || $selfMatch) {
                    $errors[] = self::issue('loop', 'UXS_REDIRECTS_E_LOOP');
                    break;
                }
                $visited[$tkey] = true;
                $parts          = explode('?', $tkeyLc, 2);
                $partsCs        = explode('?', $tkeyCs, 2);
                $next           = self::findExact($parts[0], $parts[1] ?? '', true, $partsCs[0]);
                if ($next && $existing && (int) $next['id'] === (int) $existing['id']) {
                    $next = null;
                }
                if (!$next) {
                    break;
                }
                if ((int) $next['code'] === 410) {
                    $warnings[] = self::issue('target_is_gone', 'UXS_REDIRECTS_W_TARGET_GONE');
                    break;
                }
                $from     = $target;
                $resolved = self::resolveTarget($next);
                $target   = Url::isInternal($resolved) ? Url::toRelative($resolved) : $resolved;
                $warnings[] = self::issue('chain_flattened', 'UXS_REDIRECTS_W_CHAIN_FLATTENED', $from, $target);
                if (!Url::isInternal($target)) {
                    break;
                }
            }
        }

        // --- Warnings (SEO hints, do not block) --------------------------------
        if (!$errors) {
            $tkey = Url::targetKey($target);
            if ($tkey === '/' && \in_array($code, [301, 308], true)) {
                $warnings[] = self::issue('homepage_target', 'UXS_REDIRECTS_W_HOMEPAGE');
            }
            if (\in_array($code, [302, 307], true)) {
                $warnings[] = self::issue('temporary_code', 'UXS_REDIRECTS_W_TEMPORARY');
            }
        }

        // --- Duplicate source -------------------------------------------------
        if (!$errors) {
            $dupe = self::findByHash($sourceHash);
            if ($dupe && (!$existing || (int) $dupe['id'] !== (int) $existing['id'])) {
                if ($existing || $onConflict === 'error') {
                    $errors[] = self::issue('duplicate', 'UXS_REDIRECTS_E_DUPLICATE', (int) $dupe['id']);
                } elseif ($onConflict === 'skip') {
                    return self::result('skipped', (int) $dupe['id'], $source, self::resolveTarget($dupe), [], [self::issue('duplicate', 'UXS_REDIRECTS_W_DUPLICATE_SKIPPED')]);
                } else {
                    $existing = $dupe;
                    // A CSV call that says nothing about the match options must not reset what the rule has.
                    if (!\array_key_exists('case_sensitive', $input)) {
                        $caseSensitive = (bool) (int) ($existing['case_sensitive'] ?? 0);
                        $sourceCs      = $caseSensitive && $matchType === 'exact' ? $parsedCs : '';
                    }
                    if (!\array_key_exists('query_mode', $input) && $matchType === 'exact') {
                        $queryMode = ((string) ($existing['query_mode'] ?? '')) === '' ? 'default' : (string) $existing['query_mode'];
                    }
                }
            }
        }

        $targetUrl = $code === 410 ? '' : Url::absolute($target);

        if ($errors) {
            return self::result('error', $existing ? (int) $existing['id'] : null, $source, $targetUrl, $errors, $warnings);
        }

        $tkey = Url::targetKey($target);
        $data = [
            'source'         => $source,
            'source_key'     => $sourceKey,
            'source_hash'    => $sourceHash,
            'source_cs'      => $sourceCs,
            'case_sensitive' => $caseSensitive ? 1 : 0,
            'query_mode'     => $queryMode === 'default' ? '' : $queryMode,
            'match_type'     => $matchType,
            'target'         => $target,
            'target_hash'    => $tkey !== '' && !$isTemplate ? Url::hash('target', $tkey) : '',
            'code'           => $code,
            'enabled'        => \array_key_exists('enabled', $input) ? (filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1,
            'origin'         => $existing ? (string) $existing['origin'] : $origin,
            'note'           => mb_substr(trim(strip_tags((string) ($input['note'] ?? ''))), 0, 255),
            'position'       => (int) ($input['position'] ?? ($existing['position'] ?? 0)),
        ];

        if ($existing && self::unchanged($existing, $data)) {
            return self::result('unchanged', (int) $existing['id'], $source, $targetUrl, [], $warnings);
        }
        if ($dryRun) {
            return self::result($existing ? 'would_update' : 'would_create', $existing ? (int) $existing['id'] : null, $source, $targetUrl, [], $warnings);
        }

        $db                 = self::db();
        $now                = self::now();
        $data['updated_at'] = $now;
        if ($existing) {
            $row     = (object) ($data + ['id' => (int) $existing['id']]);
            $db->updateObject(self::TABLE, $row, 'id');
            $ruleId = (int) $existing['id'];
            $status = 'updated';
        } else {
            $data['created_at'] = $now;
            // Counters travel with a NEW rule only (CSV re-import); an existing rule's live counters are never overwritten.
            if ((int) ($input['hits'] ?? 0) > 0) {
                $data['hits'] = (int) $input['hits'];
            }
            $lastHit = self::cleanDatetime($input['last_hit'] ?? '');
            if ($lastHit !== '') {
                $data['last_hit'] = $lastHit;
            }
            $row = (object) $data;
            $db->insertObject(self::TABLE, $row, 'id');
            $ruleId = (int) $row->id;
            $status = 'created';
        }

        // Only a lone rule is "the" way out of its source, so only then can older rules that point at this source skip
        // it. A case sensitive rule is not the way out for every spelling of its source, so it never re-points others.
        if ($matchType === 'exact' && !$caseSensitive) {
            $repointed = self::repointUpstream($ruleId, $sourceKey, $data);
            if ($repointed > 0) {
                $warnings[] = self::issue('upstream_repointed', 'UXS_REDIRECTS_W_UPSTREAM', $repointed);
            }
        }

        return self::result($status, $ruleId, $source, $targetUrl, [], $warnings);
    }

    /** A MySQL datetime from an import value, or '' when it is empty, malformed or the Unix epoch. */
    public static function cleanDatetime(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $value, $m) !== 1 || (int) $m[1] < 1990 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    /**
     * Saves many rules. An error on one item never stops the others.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array{summary:array<string,int>,results:array<int,array<string,mixed>>}
     */
    public static function bulk(array $items, string $onConflict, bool $dryRun, string $origin): array
    {
        $results = [];
        $summary = [];
        foreach (\array_slice($items, 0, self::BULK_MAX) as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $item['origin']  = $item['origin'] ?? $origin;
            $result          = self::save($item, $onConflict, $dryRun);
            $result['index'] = $index;
            $results[]       = $result;
            $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
        }

        return ['summary' => $summary, 'results' => $results];
    }

    /** @param int[] $ids */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $db = self::db();
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->whereIn($db->quoteName('id'), $ids))->execute();

        return $db->getAffectedRows();
    }

    /**
     * Deletes the exact rules whose source key is one of the given keys (content lives there now).
     *
     * @param string[] $keys normalised keys (path or path?query)
     */
    public static function deleteBySourceKeys(array $keys): int
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
        if (!$keys) {
            return 0;
        }
        $db  = self::db();
        $ids = $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('match_type') . ' = ' . $db->quote('exact'))
                ->whereIn($db->quoteName('source_key'), $keys, ParameterType::STRING)
        )->loadColumn() ?: [];

        return $ids ? self::delete(array_map('intval', $ids)) : 0;
    }

    /** @return array<string,mixed>|null */
    private static function findByHash(string $hash): ?array
    {
        $db  = self::db();
        $row = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('source_hash') . ' = :h')->bind(':h', $hash))->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /**
     * Rules that pointed at $sourceKey now point at this rule's target, so A->B followed by B->C ends as A->C + B->C
     * (never a 2-hop chain). A 410 source is left alone.
     *
     * @param array<string,mixed> $data stored data of the saved rule
     */
    private static function repointUpstream(int $ruleId, string $sourceKey, array $data): int
    {
        if ((int) $data['code'] === 410 || (int) $data['enabled'] !== 1) {
            return 0;
        }
        $db = self::db();
        // Never repoint the rule whose source IS the new target (would loop).
        $targetKey = Url::targetKey((string) $data['target']);
        $selfHash  = $targetKey !== '' ? Url::hash('exact', $targetKey) : '';
        $from      = Url::hash('target', $sourceKey);
        $now       = self::now();
        $target    = (string) $data['target'];
        $newHash   = (string) $data['target_hash'];
        $q = $db->createQuery()->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('target') . ' = :t')->set($db->quoteName('target_hash') . ' = :th')->set($db->quoteName('updated_at') . ' = :u')
            ->where($db->quoteName('target_hash') . ' = :from')->where($db->quoteName('id') . ' <> :id')->where($db->quoteName('source_hash') . ' <> :self')
            ->where($db->quoteName('code') . ' <> 410')
            ->bind(':t', $target)->bind(':th', $newHash)->bind(':u', $now)->bind(':from', $from)->bind(':id', $ruleId, ParameterType::INTEGER)->bind(':self', $selfHash);
        $db->setQuery($q)->execute();

        return $db->getAffectedRows();
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $data */
    private static function unchanged(array $row, array $data): bool
    {
        foreach ($data as $key => $value) {
            if ((string) ($row[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array{code:string,message:string} */
    private static function issue(string $code, string $key, mixed ...$args): array
    {
        return ['code' => $code, 'message' => $args ? Text::sprintf($key, ...$args) : Text::_($key)];
    }

    /**
     * @param array<int,array{code:string,message:string}> $errors
     * @param array<int,array{code:string,message:string}> $warnings
     * @return array{status:string,id:int|null,source:string,target_url:string,errors:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>}
     */
    private static function result(string $status, ?int $id, string $source, string $targetUrl, array $errors, array $warnings): array
    {
        return ['status' => $status, 'id' => $id, 'source' => $source, 'target_url' => $targetUrl, 'errors' => $errors, 'warnings' => $warnings];
    }
}

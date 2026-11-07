<?php

namespace Ux1\UxStudio\Core;

\defined('_JEXEC') or die;

/**
 * Shared helper of the core (modules: Core\SafeRegex).
 *
 * Regular expressions written by an administrator and evaluated on visitor input.
 *
 * A pattern like `(a+)+$` takes exponential time on a crafted address (ReDoS): a single request could pin a PHP
 * worker. Everything that runs an admin-written pattern against a request goes through here:
 *
 *  - the subject is cut to MAX_SUBJECT bytes (at a character boundary),
 *  - `pcre.backtrack_limit` is lowered for the call (and restored after it), so a runaway match stops quickly,
 *  - an error counts as "no match": the rule simply does not apply and the site keeps working.
 *
 * probe() runs a pattern against a few adversarial strings under a low limit when it is saved and refuses patterns
 * that hit the limit. It is a heuristic; the runtime limits are what actually protects the site.
 */
final class SafeRegex
{
    /** Longest subject a pattern is run against, in bytes. */
    public const MAX_SUBJECT = 1024;

    /** Longest pattern accepted. */
    public const MAX_PATTERN = 500;

    /** Backtrack limit while matching request data (PHP default 1 000 000). */
    public const MATCH_LIMIT = 20000;

    /** Backtrack limit of the save-time probe. */
    public const PROBE_LIMIT = 10000;

    /** Wraps a stored pattern in delimiters with UTF-8 (and case-insensitive) flags. */
    public static function delimit(string $pattern, bool $caseSensitive = false): string
    {
        return '@' . str_replace('@', '\@', $pattern) . ($caseSensitive ? '@u' : '@iu');
    }

    /** Cuts a subject to the maximum length without splitting a multi-byte character. */
    public static function clip(string $subject): string
    {
        if (\strlen($subject) <= self::MAX_SUBJECT) {
            return $subject;
        }

        return mb_strcut($subject, 0, self::MAX_SUBJECT, 'UTF-8');
    }

    /**
     * Does the (delimited) pattern match the subject? Errors, a runaway match or a bad pattern mean false.
     *
     * @param array<int|string,mixed> $matches captured groups (out)
     */
    public static function match(string $regex, string $subject, ?array &$matches = null): bool
    {
        $matches = [];

        return self::run($regex, self::clip($subject), self::MATCH_LIMIT, $matches) === 1;
    }

    /** @param array<int|string,mixed> $matches */
    private static function run(string $regex, string $subject, int $limit, array &$matches): int|false
    {
        $previous = ini_get('pcre.backtrack_limit');
        $lowered  = false;
        if ($previous !== false && (int) $previous > $limit) {
            $lowered = ini_set('pcre.backtrack_limit', (string) $limit) !== false;
        }
        try {
            $result = @preg_match($regex, $subject, $matches);
        } finally {
            if ($lowered) {
                ini_set('pcre.backtrack_limit', (string) $previous);
            }
        }

        return $result;
    }

    /** Whether a delimited pattern compiles at all. */
    public static function compiles(string $regex): bool
    {
        $none = [];

        return self::run($regex, '', self::PROBE_LIMIT, $none) !== false;
    }

    /** Catastrophic-backtracking probe for a pattern about to be saved (false = invalid or too slow). */
    public static function probe(string $regex): bool
    {
        if (!self::compiles($regex)) {
            return false;
        }
        $subjects = [
            str_repeat('a', 40) . '!',
            str_repeat('0', 40) . '!',
            str_repeat('ab', 24) . '!',
            str_repeat('a/', 24) . '!',
            '/' . str_repeat('a-', 24) . '?x=' . str_repeat('b', 24) . '!',
            str_repeat(' ', 40) . '!',
            str_repeat('x.', 24) . '!',
        ];
        // Strings made of each literal character of the pattern, so `(x+x+)+y` is fed x's, not only a's.
        $literals = \array_slice(array_unique(str_split((string) preg_replace('/[^A-Za-z0-9]/', '', $regex))), 0, 8);
        foreach ($literals as $char) {
            $subjects[] = str_repeat((string) $char, 40) . '!' . implode('', $literals);
        }
        foreach ($subjects as $subject) {
            $matches = [];
            if (self::run($regex, $subject, self::PROBE_LIMIT, $matches) === false && preg_last_error() !== PREG_NO_ERROR) {
                return false;
            }
        }

        return true;
    }
}

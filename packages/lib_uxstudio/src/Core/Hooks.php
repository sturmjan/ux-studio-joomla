<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die;

/**
 * Minimal filter registry for cooperation between modules without them knowing each other. A module that owns a
 * feature calls Hooks::filter('name', $value, ...) and any other module may have registered a callback for it in boot().
 *
 * Known hooks:
 *   csp.sources  (security-optimization)  array<string,string[]>  directive => extra sources, e.g. ['form-action' => ['https://ca.example.com']].
 *                                          Callbacks receive and return the whole array. The sources are validated by the CSP builder.
 *
 *   Hooks::add('csp.sources', static function (array $extra): array {
 *       $extra['form-action'][] = 'https://app.example.com';
 *       return $extra;
 *   });
 */
final class Hooks
{
    /** @var array<string,array<int,array<int,callable>>> name => priority => callbacks */
    private static array $filters = [];

    public static function add(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = $callback;
    }

    public static function has(string $name): bool
    {
        return !empty(self::$filters[$name]);
    }

    /**
     * Runs the callbacks (lower priority number first) and returns the filtered value. A callback that throws or returns
     * a value of another type is skipped and logged: one broken module must not break the feature of another.
     */
    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        $priorities = self::$filters[$name] ?? [];
        ksort($priorities);
        foreach ($priorities as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $result = $callback($value, ...$args);
                } catch (\Throwable $e) {
                    Log::add(sprintf('Hook %s failed: %s', $name, $e->getMessage()), Log::WARNING, 'uxstudio');
                    continue;
                }
                if (get_debug_type($result) === get_debug_type($value)) {
                    $value = $result;
                }
            }
        }

        return $value;
    }
}

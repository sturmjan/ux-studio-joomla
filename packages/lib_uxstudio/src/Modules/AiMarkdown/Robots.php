<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

\defined('_JEXEC') or die;

/**
 * robots.txt helper: reports what the site's current robots.txt says about each known AI bot and builds a ready-to-paste
 * snippet for a chosen policy. The module never edits robots.txt itself (it is a physical file the admin owns and a
 * generated rule would be easy to get wrong), it only shows the facts and the suggestion.
 */
final class Robots
{
    public const POLICIES = ['allow_all', 'block_training', 'block_all'];

    /** @return array{exists:bool,file:string,bots:array<int,array<string,string>>} */
    public static function analyze(): array
    {
        $file = JPATH_ROOT . '/robots.txt';
        $text = is_file($file) ? (string) @file_get_contents($file, false, null, 0, 262144) : '';
        $groups = self::parse($text);
        $bots   = [];
        $seen   = [];
        foreach (BotDetector::BOTS as $token => [$name, $purpose]) {
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            [$status, $source] = self::statusFor($groups, $token);
            $bots[] = ['token' => $token, 'name' => $name, 'purpose' => $purpose, 'status' => $status, 'source' => $source];
        }

        return ['exists' => $text !== '', 'file' => 'robots.txt', 'bots' => $bots];
    }

    /** @return array<int,array{agents:string[],rules:array<int,array{0:string,1:string}>}> */
    private static function parse(string $text): array
    {
        $groups = [];
        $cur    = null;
        $lastWasAgent = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if (!$lastWasAgent || $cur === null) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $cur      = \count($groups) - 1;
                }
                $groups[$cur]['agents'][] = strtolower($value);
                $lastWasAgent = true;
            } elseif (($field === 'allow' || $field === 'disallow') && $cur !== null) {
                $groups[$cur]['rules'][] = [$field, $value];
                $lastWasAgent = false;
            } else {
                $lastWasAgent = false;
            }
        }

        return $groups;
    }

    /** @return array{0:string,1:string} [blocked|allowed|default, specific|wildcard|none] */
    private static function statusFor(array $groups, string $token): array
    {
        $find = static function (string $agent) use ($groups): ?array {
            foreach ($groups as $g) {
                foreach ($g['agents'] as $a) {
                    if ($a === $agent) {
                        return $g;
                    }
                }
            }

            return null;
        };
        $specific = $find(strtolower($token));
        $group    = $specific ?? $find('*');
        $source   = $specific ? 'specific' : ($group ? 'wildcard' : 'none');
        if (!$group) {
            return ['default', 'none'];
        }
        $blocked = false;
        $allowed = false;
        foreach ($group['rules'] as [$kind, $value]) {
            if ($kind === 'disallow' && $value === '/') {
                $blocked = true;
            } elseif ($kind === 'allow' && ($value === '/' || $value === '')) {
                $allowed = true;
            }
        }

        return [$blocked && !$allowed ? 'blocked' : 'allowed', $source];
    }

    public static function snippet(string $policy): string
    {
        $policy = \in_array($policy, self::POLICIES, true) ? $policy : 'allow_all';
        $out    = "# AI crawlers (policy: " . str_replace('_', ' ', $policy) . ")\n";
        $done   = [];
        foreach (BotDetector::BOTS as $token => [$name, $purpose]) {
            // Search engines are not "AI bots" to block here, they stay under the normal rules.
            if (\in_array($token, ['Googlebot', 'Bingbot', 'Applebot'], true) || isset($done[$token])) {
                continue;
            }
            $done[$token] = true;
            $block = $policy === 'block_all' || ($policy === 'block_training' && $purpose === 'training');
            $out  .= "User-agent: " . $token . "\n" . ($block ? "Disallow: /\n" : "Allow: /\n") . "\n";
        }

        return rtrim($out) . "\n";
    }
}

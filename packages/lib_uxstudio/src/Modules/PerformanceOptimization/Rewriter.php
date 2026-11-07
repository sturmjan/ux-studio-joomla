<?php

namespace Ux1\UxStudio\Modules\PerformanceOptimization;

\defined('_JEXEC') or die;

/**
 * Rewrites the rendered HTML page: lazy loading of images / iframes, a priority hint for the first big image,
 * deferred external scripts and optional minification. Pure string work (no Joomla calls).
 *
 * Safety model: everything that must stay byte-exact (script / style / noscript / textarea / pre / template bodies and
 * comments) is swapped for placeholders first, the transformations only see the remaining markup and the placeholders
 * are put back at the end. Any regex failure returns the original page untouched.
 */
final class Rewriter
{
    private const TAG = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /** @var string[] protected fragments, index = placeholder number */
    private array $held = [];

    /** @var array<string,mixed> */
    private array $o;

    /** @param array<string,mixed> $o options, see defaults() */
    public function __construct(array $o)
    {
        $this->o = $o + self::defaults();
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'lazy_images'    => false,
            'lazy_iframes'   => false,
            'lazy_skip'      => 1,
            'lcp_priority'   => false,
            'lazy_exclude'   => [],
            'defer_scripts'  => false,
            'defer_exclude'  => [],
            'minify_html'    => false,
            'strip_comments' => false,
        ];
    }

    public function enabled(): bool
    {
        return $this->o['lazy_images'] || $this->o['lazy_iframes'] || $this->o['lcp_priority'] || $this->o['defer_scripts'] || $this->o['minify_html'] || $this->o['strip_comments'];
    }

    public function process(string $html): string
    {
        // A literal placeholder byte in the page would corrupt the restore step: leave such pages alone.
        if (!$this->enabled() || $html === '' || str_contains($html, "\x01") || str_contains($html, "\x02")) {
            return $html;
        }
        $this->held = [];

        $work = $this->hold($html);
        if ($work === null) {
            return $html;
        }

        if ($this->o['lazy_images'] || $this->o['lcp_priority']) {
            $work = $this->images($work) ?? $work;
        }
        if ($this->o['lazy_iframes']) {
            $work = $this->iframes($work) ?? $work;
        }
        if ($this->o['minify_html']) {
            $work = $this->minify($work) ?? $work;
        }

        $held = $this->held;
        $out  = preg_replace_callback('/\x01(\d+)\x02/', static fn (array $m): string => $held[(int) $m[1]] ?? '', $work);
        $this->held = [];

        return \is_string($out) ? $out : $html;
    }

    /** Swaps protected fragments for placeholders (and applies the script / comment rules while doing so). */
    private function hold(string $html): ?string
    {
        // Any other tag is consumed whole (third alternative) so that "<script" or "<!--" inside a quoted attribute value
        // is never mistaken for the real thing.
        $pattern = '~<!--.*?-->|<(script|style|noscript|textarea|pre|template)\b' . self::TAG . '>.*?</\1\s*>|<[A-Za-z/!]' . self::TAG . '>~is';
        $out     = preg_replace_callback($pattern, function (array $m): string {
            $chunk = $m[0];
            if (($m[1] ?? '') === '' && !str_starts_with($chunk, '<!--')) {
                return $chunk;
            }
            if (str_starts_with($chunk, '<!--')) {
                // Conditional comments and the Google Ads "googleoff" markers carry meaning: always keep them.
                if ($this->o['strip_comments'] && !preg_match('/^<!--\s*(\[if|<!\[endif|googleo(n|ff))/i', $chunk) && !preg_match('/\[endif\]-->$/', $chunk)) {
                    return '';
                }
            } elseif (strtolower($m[1]) === 'script' && $this->o['defer_scripts']) {
                $chunk = $this->deferScript($chunk);
            }
            $this->held[] = $chunk;

            return "\x01" . (\count($this->held) - 1) . "\x02";
        }, $html);

        return \is_string($out) ? $out : null;
    }

    /** Adds `defer` to the opening tag of an external classic script unless it is async / deferred / a module / excluded. */
    private function deferScript(string $chunk): string
    {
        if (!preg_match('~^<script\b(' . self::TAG . ')>~i', $chunk, $m)) {
            return $chunk;
        }
        $attrs = $m[1];
        if (!preg_match('/(^|\s)src\s*=/i', $attrs) || preg_match('/(^|\s)(defer|async|nomodule)(\s|=|\/|$)/i', $attrs)) {
            return $chunk;
        }
        if (preg_match('/(^|\s)type\s*=\s*["\']?([^"\'\s>]*)/i', $attrs, $t) && !\in_array(strtolower($t[2]), ['', 'text/javascript', 'application/javascript'], true)) {
            return $chunk;
        }
        if ($this->excluded($attrs, $this->o['defer_exclude'])) {
            return $chunk;
        }
        $open = '<script' . rtrim($attrs, " \t\r\n/") . ' defer>';

        return $open . substr($chunk, \strlen($m[0]));
    }

    /**
     * Splits the markup into tags and text and lets the callback rewrite tags of one name. Only real tags are seen:
     * a "<img ..." inside a quoted attribute value (data-bs-content, JSON in data-*) belongs to the enclosing tag.
     *
     * @param callable(string,int):string $fn receives the tag and its position among the tags of that name
     */
    private function mapTags(string $work, string $name, callable $fn): ?string
    {
        $parts = preg_split('~(<[A-Za-z/!]' . self::TAG . '>)~', $work, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!\is_array($parts)) {
            return null;
        }
        $n = 0;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 && preg_match('~^<' . $name . '(?=[\s/>])~i', $part)) {
                $parts[$i] = $fn($part, $n++);
            }
        }

        return implode('', $parts);
    }

    private function images(string $work): ?string
    {
        $skip    = (int) $this->o['lazy_skip'];
        $content = 0;      // content images seen so far (the logo and small icons do not use up the above-the-fold window)
        $lcpDone = false;

        return $this->mapTags($work, 'img', function (string $tag) use ($skip, &$content, &$lcpDone): string {
            $isContent = $this->isContentImage($tag);
            $own       = $this->has($tag, 'loading');
            $inWindow  = $content < $skip;
            if ($isContent && !$own) {
                ++$content;
            }
            if ($this->excluded($tag, $this->o['lazy_exclude'])) {
                return $tag;
            }
            $add = [];
            // The first sizeable image that is not explicitly lazy is the likely LCP element: ask for it early, never lazy.
            $lcp = false;
            if (!$lcpDone && $isContent && !preg_match('/\sloading\s*=\s*["\']?lazy/i', $tag)) {
                $lcpDone = true;
                $lcp     = $this->o['lcp_priority'];
                if ($lcp && !$this->has($tag, 'fetchpriority')) {
                    $add[] = 'fetchpriority="high"';
                }
            }
            if ($this->o['lazy_images'] && !$lcp && !$own && !$this->hasLazyLibrary($tag)
                && ($isContent ? !$inWindow : $content >= $skip)) {
                $add[] = 'loading="lazy"';
                if (!$this->has($tag, 'decoding')) {
                    $add[] = 'decoding="async"';
                }
            }

            return $add ? $this->addAttributes($tag, $add) : $tag;
        });
    }

    private function iframes(string $work): ?string
    {
        return $this->mapTags($work, 'iframe', function (string $tag): string {
            if ($this->has($tag, 'loading') || $this->has($tag, 'data-src') || $this->excluded($tag, $this->o['lazy_exclude']) || !$this->has($tag, 'src')) {
                return $tag;
            }

            return $this->addAttributes($tag, ['loading="lazy"']);
        });
    }

    /** Whitespace in text between tags is collapsed; tags themselves (attribute values!) stay exactly as they are. */
    private function minify(string $work): ?string
    {
        $parts = preg_split('~(<[A-Za-z/!]' . self::TAG . '>)~', $work, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!\is_array($parts)) {
            return null;
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || $part === '') {
                continue;
            }
            $parts[$i] = preg_replace_callback('/\s+/', static fn (array $m): string => str_contains($m[0], "\n") ? "\n" : ' ', $part) ?? $part;
        }

        return implode('', $parts);
    }

    private function has(string $tag, string $attr): bool
    {
        return preg_match('/\s' . preg_quote($attr, '/') . '(\s*=|\s|\/|>)/i', $tag) === 1;
    }

    /** Other lazy-loading libraries rewrite src themselves: do not fight them. */
    private function hasLazyLibrary(string $tag): bool
    {
        return preg_match('/\s(data-src|data-lazy-src|data-srcset|data-lazy)\s*=/i', $tag) === 1;
    }

    /** A logo / icon sized by its attributes is not the LCP candidate. */
    private function isContentImage(string $tag): bool
    {
        if (!$this->has($tag, 'src')) {
            return false;
        }
        foreach (['width', 'height'] as $dim) {
            if (preg_match('/\s' . $dim . '\s*=\s*["\']?(\d+)/i', $tag, $m) && (int) $m[1] > 0 && (int) $m[1] < 150) {
                return false;
            }
        }

        return stripos($tag, 'logo') === false;
    }

    /** @param string[] $needles */
    private function excluded(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $attrs */
    private function addAttributes(string $tag, array $attrs): string
    {
        $extra = ' ' . implode(' ', $attrs);
        if (str_ends_with($tag, '/>')) {
            return rtrim(substr($tag, 0, -2)) . $extra . ' />';
        }

        return rtrim(substr($tag, 0, -1)) . $extra . '>';
    }
}

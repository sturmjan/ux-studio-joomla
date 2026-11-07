<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * HTML of the feed. No inline style, no inline script, no event attributes (CSP friendly): the layout is driven by
 * modifier classes (uxs-ig--cols-N, uxs-ig--gap-N) from media/modules/instagram-feed/instagram.css, the carousel buttons
 * by instagram.js through data attributes. Images are lazy loaded and have explicit dimensions (no layout shift).
 */
final class Renderer
{
    public const COLUMNS = [1, 2, 3, 4, 5, 6, 7, 8];
    public const GAPS    = [0, 4, 8, 12, 16, 24];
    /** Columns on tablet / phone ('auto' = at most 3 / 2, see the CSS). */
    public const COLUMNS_SMALL = [1, 2, 3, 4, 5, 6];

    private const SVG_VIDEO = '<svg class="uxs-ig__badge-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>';
    private const SVG_ALBUM = '<svg class="uxs-ig__badge-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 7h13v13H4zM7 4h13v13h-1V5H7z"/></svg>';
    private const SVG_PREV  = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M15.4 6 14 4.6 6.6 12 14 19.4 15.4 18 9.4 12z"/></svg>';
    private const SVG_NEXT  = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.6 6 10 4.6 17.4 12 10 19.4 8.6 18l6-6z"/></svg>';

    /**
     * @param array<int,array<string,mixed>> $items rows of MediaStore (visible, filtered, limited)
     * @param array<string,mixed>            $o     layout (grid|carousel), columns, gap, show_caption, caption_length, show_follow,
     *                                              link_target, heading, css_class, username, show_likes, columns_tablet, columns_mobile (1-6 or 'auto'),
     *                                              allow_remote (use the Instagram CDN link when there is no local copy)
     */
    public static function render(array $items, array $o): string
    {
        static $instance = 0;
        ++$instance;

        $layout   = ($o['layout'] ?? 'grid') === 'carousel' ? 'carousel' : 'grid';
        $cols     = (int) ($o['columns'] ?? 4);
        $cols     = \in_array($cols, self::COLUMNS, true) ? $cols : 4;
        $gap      = (int) ($o['gap'] ?? 8);
        $gap      = \in_array($gap, self::GAPS, true) ? $gap : 8;
        $username = (string) ($o['username'] ?? '');
        $target   = ($o['link_target'] ?? '_blank') === '_self' ? '_self' : '_blank';
        $rel      = $target === '_blank' ? ' rel="noopener noreferrer"' : '';
        $capLen   = max(0, (int) ($o['caption_length'] ?? 100));
        $showCap  = !empty($o['show_caption']);
        $class    = trim((string) ($o['css_class'] ?? ''));
        $id       = 'uxs-ig-' . $instance;

        $classes = ['uxs-ig', 'uxs-ig--' . $layout, 'uxs-ig--cols-' . $cols, 'uxs-ig--gap-' . $gap];
        foreach (['tcols' => 'columns_tablet', 'mcols' => 'columns_mobile'] as $prefix => $opt) {
            $n = (int) ($o[$opt] ?? 0);
            if (\in_array($n, self::COLUMNS_SMALL, true)) {
                $classes[] = 'uxs-ig--' . $prefix . '-' . $n;
            }
        }
        $showLikes   = !empty($o['show_likes']);
        $allowRemote = !empty($o['allow_remote']);
        if ($class !== '') {
            foreach (preg_split('/\s+/', $class) ?: [] as $c) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $c)) {
                    $classes[] = $c;
                }
            }
        }

        $html  = '<div id="' . $id . '" class="' . self::e(implode(' ', $classes)) . '"' . ($layout === 'carousel' ? ' data-uxs-ig-carousel="1"' : '') . '>';
        $heading = trim((string) ($o['heading'] ?? ''));
        if ($heading !== '') {
            $html .= '<h3 class="uxs-ig__heading">' . self::e($heading) . '</h3>';
        }

        $html .= '<div class="uxs-ig__viewport">';
        if ($layout === 'carousel') {
            $html .= '<button type="button" class="uxs-ig__nav uxs-ig__nav--prev" data-uxs-ig-prev aria-label="' . self::e(Text::_('UXS_INSTAGRAM_FEED_PREV')) . '">' . self::SVG_PREV . '</button>';
        }
        $html .= '<ul class="uxs-ig__list">';

        foreach ($items as $item) {
            $img = self::imageUrl($item, $allowRemote);
            if ($img === '') {
                continue;
            }
            $caption = self::truncate((string) $item['caption'], $capLen > 0 ? $capLen : 2500);
            $alt     = $caption !== '' ? self::truncate((string) $item['caption'], 125) : Text::sprintf('UXS_INSTAGRAM_FEED_ALT', $username !== '' ? '@' . $username : 'Instagram');
            $badge   = '';
            if ($item['media_type'] === 'VIDEO') {
                $badge = '<span class="uxs-ig__badge" title="' . self::e(Text::_('UXS_INSTAGRAM_FEED_VIDEO')) . '">' . self::SVG_VIDEO . '</span>';
            } elseif ($item['media_type'] === 'CAROUSEL_ALBUM') {
                $badge = '<span class="uxs-ig__badge" title="' . self::e(Text::_('UXS_INSTAGRAM_FEED_ALBUM')) . '">' . self::SVG_ALBUM . '</span>';
            }

            $likes = $showLikes && $item['like_count'] !== null
                ? '<span class="uxs-ig__likes"><span aria-hidden="true">&#9829;</span> ' . (int) $item['like_count'] . '<span class="uxs-ig__sr"> ' . self::e(Text::_('UXS_INSTAGRAM_FEED_LIKES')) . '</span></span>'
                : '';
            $permalink = self::safeLink((string) $item['permalink']);
            $pic = '<span class="uxs-ig__media"><img class="uxs-ig__img" src="' . self::e($img) . '" alt="' . self::e($alt)
                . '" width="320" height="320" loading="lazy" decoding="async" referrerpolicy="no-referrer">' . $badge . $likes . '</span>';
            $cap = $showCap && $caption !== '' ? '<span class="uxs-ig__caption">' . self::e($caption) . '</span>' : '';

            $html .= '<li class="uxs-ig__item">';
            $html .= $permalink !== ''
                ? '<a class="uxs-ig__link" href="' . self::e($permalink) . '" target="' . $target . '"' . $rel . '>' . $pic . $cap . '</a>'
                : '<span class="uxs-ig__link">' . $pic . $cap . '</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        if ($layout === 'carousel') {
            $html .= '<button type="button" class="uxs-ig__nav uxs-ig__nav--next" data-uxs-ig-next aria-label="' . self::e(Text::_('UXS_INSTAGRAM_FEED_NEXT')) . '">' . self::SVG_NEXT . '</button>';
        }
        $html .= '</div>';

        if (!empty($o['show_follow']) && $username !== '') {
            $html .= '<p class="uxs-ig__follow"><a class="uxs-ig__follow-link" href="' . self::e('https://www.instagram.com/' . rawurlencode($username) . '/')
                . '" target="_blank" rel="noopener noreferrer">' . self::e(Text::sprintf('UXS_INSTAGRAM_FEED_FOLLOW', '@' . $username)) . '</a></p>';
        }

        return $html . '</div>';
    }

    /** Message for administrators when there is nothing to show (visitors get nothing). */
    public static function notice(string $message): string
    {
        return '<div class="uxs-ig-notice" role="status">' . self::e($message) . '</div>';
    }

    /**
     * Local copy when present. Without one the (expiring) Instagram CDN link is returned only when $allowRemote (the admin
     * preview, or a site with "Store images" switched off); the public feed otherwise skips the post, so a visitor
     * never contacts Meta by accident.
     */
    public static function imageUrl(array $item, bool $allowRemote = true): string
    {
        $file = (string) ($item['local_file'] ?? '');
        if ($file !== '' && ImageCache::exists($file)) {
            return ImageCache::url($file);
        }
        if (!$allowRemote) {
            return '';
        }
        $remote = Item::imageUrl($item);

        // The remote fallback is only ever an https link (the posts come from an external service).
        return str_starts_with(strtolower($remote), 'https://') ? $remote : '';
    }

    /** Defence in depth: whatever sits in the database, only an https link ends up in an href. */
    private static function safeLink(string $url): string
    {
        return preg_match('#^https://[^/\s"<>]+#i', $url) ? $url : '';
    }

    private static function truncate(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)) . '…';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

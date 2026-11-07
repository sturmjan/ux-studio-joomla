<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

\defined('_JEXEC') or die;

/** Normalisation and validation of one raw Graph API post (untrusted data from the central app). Pure functions. */
final class Item
{
    public const TYPES = ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM'];

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null null when the item is unusable
     */
    public static function normalize(array $raw): ?array
    {
        $id = (string) ($raw['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }
        $type = strtoupper((string) ($raw['media_type'] ?? 'IMAGE'));
        if (!\in_array($type, self::TYPES, true)) {
            $type = 'IMAGE';
        }

        $mediaUrl = self::url($raw['media_url'] ?? '');
        $thumb    = self::url($raw['thumbnail_url'] ?? '');
        // An album without its own media_url: the cover is the first child.
        if ($mediaUrl === '' && $thumb === '' && \is_array($raw['children']['data'] ?? null)) {
            foreach ($raw['children']['data'] as $child) {
                if (\is_array($child)) {
                    $mediaUrl = self::url($child['media_url'] ?? '');
                    $thumb    = self::url($child['thumbnail_url'] ?? '');
                    if ($mediaUrl !== '' || $thumb !== '') {
                        break;
                    }
                }
            }
        }

        $permalink = self::url($raw['permalink'] ?? '');
        $caption   = self::text($raw['caption'] ?? '');

        $ts = null;
        if (!empty($raw['timestamp']) && \is_string($raw['timestamp'])) {
            $time = strtotime($raw['timestamp']);
            $ts   = $time ? gmdate('Y-m-d H:i:s', $time) : null;
        }

        return [
            'instagram_id'    => $id,
            'media_type'      => $type,
            'media_url'       => $mediaUrl,
            'thumbnail_url'   => $thumb,
            'permalink'       => $permalink,
            'caption'         => $caption === '' ? null : $caption,
            'hashtags'        => self::hashtags($caption),
            'media_timestamp' => $ts,
            'like_count'      => isset($raw['like_count']) && is_numeric($raw['like_count']) ? max(0, (int) $raw['like_count']) : null,
            'comments_count'  => isset($raw['comments_count']) && is_numeric($raw['comments_count']) ? max(0, (int) $raw['comments_count']) : null,
        ];
    }

    /** URL of the picture to keep for a post: videos use their thumbnail (the video itself is never downloaded). */
    public static function imageUrl(array $row): string
    {
        if ($row['media_type'] === 'VIDEO') {
            return (string) $row['thumbnail_url'];
        }

        return (string) ($row['media_url'] !== '' ? $row['media_url'] : $row['thumbnail_url']);
    }

    /** Only http(s) links of a sane length; anything else (javascript:, data:, relative) becomes ''. */
    public static function url(mixed $value): string
    {
        $url = \is_string($value) ? trim($value) : '';
        if ($url === '' || \strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f"<>\\\\]/', $url)) {
            return '';
        }

        return preg_match('#^https?://[^/]+#i', $url) ? $url : '';
    }

    public static function text(mixed $value): string
    {
        $text = \is_string($value) ? $value : '';
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return mb_substr($text, 0, 2500);
    }

    /** @return string[] lower-case hashtags without the hash sign, unique, in order of appearance */
    public static function hashtags(string $caption): array
    {
        if ($caption === '' || !preg_match_all('/#([\p{L}\p{N}_]{1,100})/u', $caption, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('mb_strtolower', $m[1])));
    }

    /**
     * Comma / space separated user input to a clean list of lower-case tags.
     *
     * @return string[]
     */
    public static function tagList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', $raw) ?: [] as $tag) {
            $tag = mb_strtolower(ltrim(trim($tag), '#'));
            if ($tag !== '' && preg_match('/^[\p{L}\p{N}_]{1,100}$/u', $tag)) {
                $out[] = $tag;
            }
        }

        return array_values(array_unique($out));
    }
}

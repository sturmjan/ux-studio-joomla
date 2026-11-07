<?php

namespace Ux1\UxStudio\Modules\ReviewAggregator;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Builds the front-end markup of the review list and the schema.org JSON-LD. Pure output: every value is escaped here,
 * there is no inline script or style (CSP friendly; the show-more toggle lives in media/modules/review-aggregator/reviews.js).
 */
final class Renderer
{
    public const LAYOUTS = ['grid', 'list', 'slider', 'badge'];

    public const SCHEMA_TYPES = ['LocalBusiness', 'Organization', 'Product', 'Service'];

    private const LABELS = ['google' => 'Google', 'mapycz' => 'Mapy.cz', 'facebook' => 'Facebook', 'manual' => ''];

    /** The JSON-LD block may be printed only once per page. */
    private static bool $schemaPrinted = false;

    /**
     * @param array<int,array<string,mixed>> $reviews rows from ReviewStore::visible()
     * @param array{count:int,average:float} $aggregate
     * @param array<string,mixed>            $o        layout, show_* flags, text_limit, schema, schema_type, schema_name, schema_reviews, heading, css_class
     */
    public static function render(array $reviews, array $aggregate, array $o): string
    {
        $layout = \in_array($o['layout'] ?? 'grid', self::LAYOUTS, true) ? (string) $o['layout'] : 'grid';
        $class  = 'uxs-reviews uxs-reviews--' . $layout;
        if (!empty($o['css_class'])) {
            $class .= ' ' . htmlspecialchars((string) preg_replace('/[^A-Za-z0-9_ -]/', '', (string) $o['css_class']), ENT_QUOTES, 'UTF-8');
        }

        $html = '<section class="' . $class . '">';
        if (!empty($o['heading'])) {
            $html .= '<h3 class="uxs-reviews__heading">' . htmlspecialchars((string) $o['heading'], ENT_QUOTES, 'UTF-8') . '</h3>';
        }

        if ($reviews === [] && $aggregate['count'] === 0) {
            return $html . '<p class="uxs-reviews__empty">' . htmlspecialchars(Text::_('UXS_REVIEW_AGGREGATOR_FRONT_EMPTY'), ENT_QUOTES, 'UTF-8') . '</p></section>';
        }

        if (!empty($o['show_summary']) || $layout === 'badge') {
            $html .= self::summary($aggregate);
        }

        if ($layout !== 'badge' && $reviews !== []) {
            $html .= '<div class="uxs-reviews__list">';
            foreach ($reviews as $review) {
                $html .= self::card($review, $o);
            }
            $html .= '</div>';
        }

        $html .= '</section>';

        if (!empty($o['schema']) && $aggregate['count'] > 0 && !self::$schemaPrinted) {
            self::$schemaPrinted = true;
            $html .= self::jsonLd($reviews, $aggregate, $o);
        }

        return $html;
    }

    /** @param array{count:int,average:float} $aggregate */
    private static function summary(array $aggregate): string
    {
        if ($aggregate['count'] === 0) {
            return '';
        }
        $sep = Text::_('DECIMALS_SEPARATOR');
        $sep = mb_strlen($sep) === 1 ? $sep : '.';
        $avg = number_format($aggregate['average'], 1, $sep, '');

        return '<div class="uxs-reviews__summary">'
            . '<span class="uxs-reviews__avg">' . htmlspecialchars($avg, ENT_QUOTES, 'UTF-8') . '</span>'
            . self::stars((int) round($aggregate['average']))
            . '<span class="uxs-reviews__count">' . htmlspecialchars(Text::plural('UXS_REVIEW_AGGREGATOR_FRONT_COUNT', $aggregate['count']), ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>';
    }

    private static function stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        $html   = '<span class="uxs-reviews__stars" role="img" aria-label="' . htmlspecialchars(Text::sprintf('UXS_REVIEW_AGGREGATOR_FRONT_STARS', $rating), ENT_QUOTES, 'UTF-8') . '">';
        for ($i = 1; $i <= 5; ++$i) {
            $html .= '<span class="uxs-reviews__star' . ($i <= $rating ? ' is-filled' : '') . '" aria-hidden="true">&#9733;</span>';
        }

        return $html . '</span>';
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $o */
    private static function card(array $r, array $o): string
    {
        $source = (string) preg_replace('/[^a-z0-9_-]/', '', (string) $r['source']);
        $author = (string) $r['author'];
        $text   = (string) ($r['text'] ?? '');
        $label  = self::LABELS[$source] ?? $source;
        $esc    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '<article class="uxs-reviews__card uxs-reviews__card--' . $esc($source) . '"><header class="uxs-reviews__header">';
        if (!empty($o['show_avatar'])) {
            $html .= '<span class="uxs-reviews__avatar" aria-hidden="true">' . $esc($author !== '' ? mb_strtoupper(mb_substr($author, 0, 1)) : '?') . '</span>';
        }
        $html .= '<span class="uxs-reviews__who"><span class="uxs-reviews__name">' . $esc($author !== '' ? $author : Text::_('UXS_REVIEW_AGGREGATOR_FRONT_ANONYMOUS')) . '</span>';
        if (!empty($o['show_date']) && !empty($r['review_date'])) {
            $html .= '<time class="uxs-reviews__date" datetime="' . $esc(gmdate('Y-m-d', (int) strtotime((string) $r['review_date'] . ' UTC'))) . '">'
                . $esc((string) HTMLHelper::_('date', (string) $r['review_date'], Text::_('DATE_FORMAT_LC4'))) . '</time>';
        }
        $html .= '</span>';
        if (!empty($o['show_platform']) && $label !== '') {
            $html .= '<span class="uxs-reviews__platform uxs-reviews__platform--' . $esc($source) . '">' . $esc($label) . '</span>';
        }
        $html .= '</header>';

        if (!empty($o['show_stars']) && (int) $r['rating'] > 0) {
            $html .= self::stars((int) $r['rating']);
        }

        if ($text !== '') {
            $limit = (int) ($o['text_limit'] ?? 200);
            if ($limit > 0 && mb_strlen($text) > $limit + 20) {
                $html .= '<p class="uxs-reviews__text"><span class="uxs-reviews__excerpt">' . $esc(rtrim(mb_substr($text, 0, $limit)) . '…') . '</span>'
                    . '<span class="uxs-reviews__full" hidden>' . $esc($text) . '</span> '
                    . '<button type="button" class="uxs-reviews__more" data-more="' . $esc(Text::_('UXS_REVIEW_AGGREGATOR_FRONT_MORE')) . '" data-less="' . $esc(Text::_('UXS_REVIEW_AGGREGATOR_FRONT_LESS')) . '" aria-expanded="false">'
                    . $esc(Text::_('UXS_REVIEW_AGGREGATOR_FRONT_MORE')) . '</button></p>';
            } else {
                $html .= '<p class="uxs-reviews__text">' . $esc($text) . '</p>';
            }
        }

        return $html . '</article>';
    }

    /** @param array<int,array<string,mixed>> $reviews @param array{count:int,average:float} $agg @param array<string,mixed> $o */
    private static function jsonLd(array $reviews, array $agg, array $o): string
    {
        $type = \in_array($o['schema_type'] ?? '', self::SCHEMA_TYPES, true) ? (string) $o['schema_type'] : 'Organization';
        $data = [
            '@context'        => 'https://schema.org',
            '@type'           => $type,
            '@id'             => rtrim(Uri::root(), '/') . '/#organization',
            'name'            => (string) ($o['schema_name'] ?? ''),
            'url'             => Uri::root(),
            'aggregateRating' => [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format($agg['average'], 1, '.', ''),
                'reviewCount' => $agg['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ],
        ];
        if ($type === 'LocalBusiness' && !empty($o['schema_image'])) {
            $data['image'] = (string) $o['schema_image'];
        }
        if (!empty($o['schema_reviews'])) {
            $items = [];
            foreach ($reviews as $r) {
                // Google's rules: reviews taken from other sites (Google, Facebook, ...) must not be marked up as the business's own.
                if ((int) $r['rating'] < 1 || (string) $r['source'] !== 'manual') {
                    continue;
                }
                $item = [
                    '@type'        => 'Review',
                    'author'       => ['@type' => 'Person', 'name' => (string) ($r['author'] !== '' ? $r['author'] : Text::_('UXS_REVIEW_AGGREGATOR_FRONT_ANONYMOUS'))],
                    'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string) (int) $r['rating'], 'bestRating' => '5', 'worstRating' => '1'],
                ];
                if (!empty($r['review_date'])) {
                    $item['datePublished'] = gmdate('Y-m-d', (int) strtotime((string) $r['review_date'] . ' UTC'));
                }
                if ((string) ($r['text'] ?? '') !== '') {
                    $item['reviewBody'] = (string) $r['text'];
                }
                $items[] = $item;
            }
            if ($items) {
                $data['review'] = $items;
            }
        }

        // JSON_HEX_* keeps user text from closing the script element; ld+json is a data block, not executed code.
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}

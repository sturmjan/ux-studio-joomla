<?php

namespace Ux1\UxStudio\Modules\PopupManager;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * Builds the front-end markup of one popup. The dialog is rendered hidden into the page; popup-manager.js reads the
 * JSON in data-uxs-popup, applies colours through the CSSOM (no inline style attributes, CSP friendly) and shows it.
 */
final class Renderer
{
    /**
     * @param array<string,mixed> $popup  hydrated popup (Store::get)
     * @param array<string,mixed> $options storage, track (url or ''), preview (bool)
     */
    public static function html(array $popup, array $options): string
    {
        $id       = (int) $popup['id'];
        $position = \in_array($popup['position'], Store::POSITIONS, true) ? (string) $popup['position'] : 'center';
        $modal    = $position === 'center';
        $e        = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $headline = Support::text($popup['headline'], 200);
        $body     = Support::html($popup['body']);
        $image    = Support::mediaUrl($popup['image']);
        $ctaText  = Support::text($popup['cta_text'], 80);
        $ctaUrl   = Support::url($popup['cta_url']);
        $newTab   = !empty($popup['cta_new_tab']) && $ctaUrl !== '' && $ctaUrl !== '#';

        $preview = !empty($options['preview']);
        $config  = [
            'id'        => $id,
            'trigger'   => $preview ? 'immediate' : (string) $popup['trigger_type'],
            'delay'     => (int) $popup['delay_seconds'],
            'scroll'    => (int) $popup['scroll_percent'],
            'exitDelay' => (int) $popup['exit_delay'],
            'frequency' => $preview ? 'always' : (string) $popup['frequency'],
            'days'      => (int) $popup['frequency_days'],
            'stopClick' => (bool) $popup['stop_after_click'],
            'devices'   => $preview ? [] : array_values((array) $popup['devices']),
            'from'      => $preview ? 0 : Support::localToTimestamp($popup['starts_at']),
            'until'     => $preview ? 0 : Support::localToTimestamp($popup['ends_at']),
            'storage'   => (string) ($options['storage'] ?? 'local'),
            'track'     => $preview ? '' : (string) ($options['track'] ?? ''),
            'modal'     => $modal,
            'animation' => (string) $popup['animation'],
            'width'     => (int) $popup['width'],
            'overlay'   => (int) $popup['overlay_opacity'],
            'colors'    => [
                'bg'     => Support::color($popup['bg_color'], '#ffffff'),
                'text'   => Support::color($popup['text_color'], '#1f2937'),
                'accent' => Support::color($popup['accent_color'], '#2563eb'),
                'cta'    => Support::color($popup['cta_text_color'], '#ffffff'),
            ],
        ];
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $titleId = 'uxs-popup-' . $id . '-title';
        $label   = $headline !== '' ? ' aria-labelledby="' . $titleId . '"' : ' aria-label="' . $e(Support::text($popup['title'], 190)) . '"';

        $out  = '<div class="uxs-popup uxs-popup--' . $e($position) . '" id="uxs-popup-' . $id . '" data-uxs-popup="' . $e($json) . '" hidden>';
        $out .= $modal ? '<div class="uxs-popup__backdrop" data-uxs-close></div>' : '';
        $out .= '<div class="uxs-popup__dialog" role="dialog" aria-modal="' . ($modal ? 'true' : 'false') . '"' . $label . ' tabindex="-1">';
        $out .= '<button type="button" class="uxs-popup__close" data-uxs-close aria-label="' . $e(Text::_('UXS_POPUP_MANAGER_CLOSE')) . '"><span aria-hidden="true">&times;</span></button>';
        if ($image !== '') {
            $out .= '<img class="uxs-popup__image" src="' . $e($image) . '" alt="' . $e(Support::text($popup['image_alt'], 200)) . '" loading="lazy" decoding="async">';
        }
        $out .= '<div class="uxs-popup__content">';
        if ($headline !== '') {
            $out .= '<h2 class="uxs-popup__headline" id="' . $titleId . '">' . $e($headline) . '</h2>';
        }
        if ($body !== '') {
            $out .= '<div class="uxs-popup__body">' . $body . '</div>';
        }
        if ($ctaText !== '' && $ctaUrl !== '') {
            $href = $ctaUrl;
            if (str_starts_with($href, '/') && !str_starts_with($href, Uri::root(true) . '/') && Uri::root(true) !== '') {
                // A path typed relative to the site root: prefix the installation folder.
                $href = Uri::root(true) . $href;
            }
            $out .= '<a class="uxs-popup__cta" href="' . $e($href) . '" data-uxs-cta' . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $e($ctaText) . '</a>';
        }
        $out .= '</div></div></div>';

        return $out;
    }
}

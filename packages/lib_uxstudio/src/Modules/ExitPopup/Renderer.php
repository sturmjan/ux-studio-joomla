<?php

namespace Ux1\UxStudio\Modules\ExitPopup;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Modules\PopupManager\Support;

\defined('_JEXEC') or die;

/**
 * Markup + client configuration of the exit popup. The dialog is rendered hidden; exit-popup.js applies the colours
 * through the CSSOM (no inline styles), arms the exit detection and handles the e-mail form.
 */
final class Renderer
{
    /**
     * @param array<string,mixed> $s       module settings
     * @param array<string,mixed> $options preview (bool)
     */
    public static function html(array $s, array $options): string
    {
        $e       = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $preview = !empty($options['preview']);
        $text    = static fn (string $key, string $lang): string => ($v = Support::text($s[$key] ?? '', 300)) !== '' ? $v : Text::_($lang);

        $headline = $text('headline', 'UXS_EXIT_POPUP_DEF_HEADLINE');
        $body     = Support::html($s['body'] ?? '');
        $image    = Support::mediaUrl($s['image'] ?? '');
        $email    = !empty($s['enable_email']);
        $consent  = Support::text($s['consent_text'] ?? '', 300);
        $ctaUrl   = Support::url($s['cta_url'] ?? '#');
        $ctaText  = $text('cta_text', 'UXS_EXIT_POPUP_DEF_CTA');
        $showCta  = !empty($s['enable_cta']) && $ctaUrl !== '';
        $newTab   = !empty($s['cta_new_tab']) && $ctaUrl !== '#';
        $layout   = ($s['layout'] ?? '') === 'horizontal' ? 'horizontal' : 'vertical';

        $config = [
            'delay'       => Support::intIn($s['delay_seconds'] ?? null, 0, 600, 2),
            'frequency'   => $preview ? 'always' : (string) ($s['frequency_mode'] ?? 'session'),
            'days'        => Support::intIn($s['frequency_days'] ?? null, 1, 365, 7),
            'storage'     => (string) ($options['storage'] ?? 'local'),
            'devices'     => $preview ? [] : Support::pick($s['devices'] ?? [], ['desktop', 'tablet', 'mobile']),
            'from'        => $preview ? 0 : Support::localToTimestamp($s['starts_at'] ?? ''),
            'until'       => $preview ? 0 : Support::localToTimestamp($s['ends_at'] ?? ''),
            'preview'     => $preview,
            'width'       => Support::intIn($s['width'] ?? null, 320, 900, 480),
            'overlay'     => Support::intIn($s['overlay_opacity'] ?? null, 0, 100, 60),
            'colors'      => [
                'bg'     => Support::color($s['bg_color'] ?? '', '#ffffff'),
                'title'  => Support::color($s['title_color'] ?? '', '#1f2937'),
                'text'   => Support::color($s['text_color'] ?? '', '#4b5563'),
                'button' => Support::color($s['cta_bg_color'] ?? '', '#2563eb'),
                'buttonText' => Support::color($s['cta_text_color'] ?? '', '#ffffff'),
            ],
            'detect'      => [
                'mouseLeave'      => !empty($s['detect_mouse_leave']),
                'mouseLeaveDelay' => Support::intIn($s['mouse_leave_delay'] ?? null, 0, 5000, 500),
                'tabChange'       => !empty($s['detect_tab_change']),
                'windowBlur'      => !empty($s['detect_window_blur']),
                'idle'            => !empty($s['detect_idle']),
                'idleTimeout'     => Support::intIn($s['idle_timeout'] ?? null, 1, 3600, 30),
                'scrollUp'        => !empty($s['detect_scroll_up']),
                'scrollUpDesktop' => Support::intIn($s['scroll_up_desktop'] ?? null, 50, 5000, 400),
                'scrollUpMobile'  => Support::intIn($s['scroll_up_mobile'] ?? null, 50, 5000, 200),
                'timeOnPage'      => Support::intIn($s['time_on_page'] ?? null, 0, 3600, 0),
            ],
            'subscribeUrl' => $email && !$preview ? Uri::root(true) . '/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=exit-popup/subscribe' : '',
            'messages'     => [
                'success' => $text('email_success_message', 'UXS_EXIT_POPUP_DEF_SUCCESS'),
                'invalid' => Text::_('UXS_EXIT_POPUP_MSG_INVALID'),
                'consent' => Text::_('UXS_EXIT_POPUP_MSG_CONSENT'),
                'error'   => Text::_('UXS_EXIT_POPUP_MSG_ERROR'),
            ],
        ];
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $out  = '<div class="uxs-exit" id="uxs-exit-popup" data-uxs-exit="' . $e($json) . '" hidden>';
        $out .= '<div class="uxs-exit__backdrop" data-uxs-close></div>';
        $out .= '<div class="uxs-exit__dialog uxs-exit__dialog--' . $layout . '" role="dialog" aria-modal="true" aria-labelledby="uxs-exit-title" tabindex="-1">';
        $out .= '<button type="button" class="uxs-exit__close" data-uxs-close aria-label="' . $e(Text::_('UXS_EXIT_POPUP_CLOSE')) . '"><span aria-hidden="true">&times;</span></button>';
        if ($image !== '') {
            $out .= '<div class="uxs-exit__media"><img src="' . $e($image) . '" alt="' . $e(Support::text($s['image_alt'] ?? '', 200)) . '" loading="lazy" decoding="async"></div>';
        }
        $out .= '<div class="uxs-exit__inner">';
        $out .= '<h2 class="uxs-exit__headline" id="uxs-exit-title">' . $e($headline) . '</h2>';
        if ($body !== '') {
            $out .= '<div class="uxs-exit__body">' . $body . '</div>';
        }
        if ($email) {
            $placeholder = $text('email_placeholder', 'UXS_EXIT_POPUP_DEF_EMAIL');
            $button      = $text('email_button_text', 'UXS_EXIT_POPUP_DEF_SUBSCRIBE');
            $out .= '<form class="uxs-exit__form" novalidate>';
            $out .= '<label class="uxs-exit__sr" for="uxs-exit-email">' . $e($placeholder) . '</label>';
            $out .= '<input class="uxs-exit__email" id="uxs-exit-email" type="email" name="email" required autocomplete="email" placeholder="' . $e($placeholder) . '">';
            // Honeypot: invisible to people, bots fill it.
            $out .= '<input class="uxs-exit__trap" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">';
            $out .= '<button class="uxs-exit__submit" type="submit">' . $e($button) . '</button>';
            if ($consent !== '') {
                $out .= '<label class="uxs-exit__consent"><input type="checkbox" name="consent" required> <span>' . $e($consent) . '</span></label>';
            }
            $out .= '</form>';
        }
        $out .= '<p class="uxs-exit__message" role="status" aria-live="polite"></p>';
        if ($showCta) {
            $href = $ctaUrl;
            if (str_starts_with($href, '/') && Uri::root(true) !== '' && !str_starts_with($href, Uri::root(true) . '/')) {
                $href = Uri::root(true) . $href;
            }
            $out .= '<a class="uxs-exit__cta" href="' . $e($href) . '" data-uxs-cta' . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $e($ctaText) . '</a>';
        }
        $out .= '</div></div></div>';

        return $out;
    }
}

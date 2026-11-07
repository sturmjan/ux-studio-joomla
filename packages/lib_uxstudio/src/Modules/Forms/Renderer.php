<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Renders a published form as HTML. No inline scripts or styles: behaviour comes from media/.../forms.js (WebAssetManager),
 * configuration travels in data-* attributes. Used by the {uxsform N} shortcode and the mod_uxstudio_form module.
 */
final class Renderer
{
    private static int $instances = 0;

    private static bool $inlineAccent = false;

    public static function endpoint(): string
    {
        return Uri::root(true) . '/index.php?option=com_uxstudio&task=public.dispatch&format=json&route=forms/submit';
    }

    public static function render(ModuleContext $ctx, int $formId): string
    {
        $form = (new Forms($ctx->db()))->get($formId);
        if ($form === null || $form['status'] !== 'published') {
            return '';
        }
        $s = $form['settings'];
        $n = ++self::$instances;

        if ($ctx->setting('load_css', true)) {
            $ctx->useStyle('forms.css');
        }
        $ctx->useScript('forms.js');

        $accent = (string) $ctx->setting('accent_color', '');
        $doc    = $ctx->app()->getDocument();
        if (!self::$inlineAccent && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) && $doc instanceof HtmlDocument) {
            // Goes through the WebAssetManager so Joomla adds the CSP nonce; no raw <style> in the markup.
            $doc->getWebAssetManager()->addInlineStyle('.uxs-form{--uxs-form-accent:' . $accent . ';}');
            self::$inlineAccent = true;
        }

        $useTurnstile = !empty($s['turnstile']) && (string) $ctx->setting('turnstile_site_key', '') !== '' && (string) $ctx->setting('turnstile_secret', '') !== '';
        if ($useTurnstile && $doc instanceof HtmlDocument) {
            $doc->getWebAssetManager()->registerAndUseScript('uxs.forms.turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', ['version' => ''], ['async' => true, 'defer' => true]);
        }

        $e  = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $id = 'uxsf' . $formId . '_' . $n;

        $html  = '<div class="uxs-form-wrap" id="' . $id . '">';
        $html .= '<form class="uxs-form" action="#" data-uxs-form'
            . ' data-endpoint="' . $e(self::endpoint()) . '" data-form-id="' . $formId . '" data-ts="' . $e(Submitter::timeToken($formId)) . '"'
            . ' data-msg-sending="' . $e(Text::_('UXS_FORMS_FE_SENDING')) . '" data-msg-error="' . $e(Text::_('UXS_FORMS_FE_ERROR')) . '"'
            . ' data-msg-size="' . $e(Text::_('UXS_FORMS_FE_TOO_LARGE')) . '" data-msg-count="' . $e(Text::_('UXS_FORMS_ERR_FILES_COUNT')) . '">';
        $html .= '<div class="uxs-form__grid">';

        foreach ($form['fields'] as $f) {
            $html .= self::field($f, $id, $e);
        }

        $html .= '</div>';
        // Honeypot: invisible to people, tempting for bots (hidden through the stylesheet, not inline).
        $html .= '<div class="uxs-form__hp" aria-hidden="true" hidden><label>' . $e(Text::_('UXS_FORMS_FE_HONEYPOT')) . '<input type="text" name="uxs_website" value="" tabindex="-1" autocomplete="off"></label></div>';
        if ($useTurnstile) {
            $html .= '<div class="cf-turnstile uxs-form__captcha" data-sitekey="' . $e((string) $ctx->setting('turnstile_site_key', '')) . '"></div>';
        }
        $label = $s['submit_label'] !== '' ? $s['submit_label'] : Text::_('UXS_FORMS_FE_SUBMIT');
        $html .= '<div class="uxs-form__actions"><button type="submit" class="uxs-form__submit">' . $e($label) . '</button></div>';
        $html .= '<div class="uxs-form__status" role="status" aria-live="polite"></div>';
        $html .= '</form><noscript><p class="uxs-form__noscript">' . $e(Text::_('UXS_FORMS_FE_NOSCRIPT')) . '</p></noscript></div>';

        return $html;
    }

    /** Root-relative paths ("/privacy") get the site's sub-folder prepended, like the redirect URL does. */
    private static function localUrl(string $url): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return rtrim(Uri::root(true), '/') . $url;
        }

        return $url;
    }

    /** @param array<string,mixed> $f */
    private static function field(array $f, string $uid, callable $e): string
    {
        $key  = $f['key'];
        $type = $f['type'];
        $fid  = $uid . '_' . $key;

        if ($type === 'hidden') {
            return '<input type="hidden" name="' . $e($key) . '" value="' . $e((string) $f['default_value']) . '" data-field="' . $e($key) . '">';
        }

        $wrapAttr = ' data-field="' . $e($key) . '"';
        if ($f['conditions']) {
            $wrapAttr .= ' data-conditions="' . $e((string) json_encode($f['conditions'], JSON_UNESCAPED_UNICODE)) . '" data-logic="' . $e((string) $f['logic']) . '"';
        }
        $classes = 'uxs-form__field uxs-w-' . (int) $f['width'] . ' uxs-type-' . $e($type) . ($f['css_class'] !== '' ? ' ' . $e($f['css_class']) : '');
        $out     = '<div class="' . $classes . '"' . $wrapAttr . '>';

        if ($type === 'html') {
            return $out . '<div class="uxs-form__html">' . $f['html'] . '</div></div>';
        }

        $req       = !empty($f['required']);
        $star      = $req ? ' <span class="uxs-form__req" aria-hidden="true">*</span>' : '';
        $describe  = ($f['help'] !== '' ? $fid . '_help ' : '') . $fid . '_err';
        $labelText = $e((string) $f['label']);
        $common    = ' name="' . $e($key) . '" id="' . $fid . '"' . ($req ? ' required' : '') . ' aria-describedby="' . trim($describe) . '"';

        switch ($type) {
            case 'textarea':
                $out .= '<label for="' . $fid . '">' . $labelText . $star . '</label><textarea' . $common . ' rows="' . (int) ($f['rows'] ?? 5) . '"'
                    . ($f['placeholder'] !== '' ? ' placeholder="' . $e($f['placeholder']) . '"' : '')
                    . (!empty($f['minlength']) ? ' minlength="' . (int) $f['minlength'] . '"' : '')
                    . (!empty($f['maxlength']) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '') . '>' . $e((string) $f['default_value']) . '</textarea>';
                break;

            case 'select':
                $out .= '<label for="' . $fid . '">' . $labelText . $star . '</label><select' . $common . '><option value="">' . $e($f['placeholder'] !== '' ? $f['placeholder'] : Text::_('UXS_FORMS_FE_CHOOSE')) . '</option>';
                foreach ($f['options'] ?? [] as $o) {
                    $out .= '<option value="' . $e($o['value']) . '"' . ($o['value'] === $f['default_value'] ? ' selected' : '') . '>' . $e($o['label']) . '</option>';
                }
                $out .= '</select>';
                break;

            case 'radio':
            case 'checkbox_group':
                $inputType = $type === 'radio' ? 'radio' : 'checkbox';
                $name      = $key . ($type === 'checkbox_group' ? '[]' : '');
                $out .= '<fieldset class="uxs-form__choices" aria-describedby="' . trim($describe) . '"><legend>' . $labelText . $star . '</legend>';
                foreach (array_values($f['options'] ?? []) as $i => $o) {
                    $oid = $fid . '_' . $i;
                    $out .= '<label class="uxs-form__choice" for="' . $oid . '"><input type="' . $inputType . '" id="' . $oid . '" name="' . $e($name) . '" value="' . $e($o['value']) . '"'
                        . ($type === 'radio' && $req ? ' required' : '') . ($o['value'] === $f['default_value'] ? ' checked' : '') . '> <span>' . $e($o['label']) . '</span></label>';
                }
                $out .= '</fieldset>';
                break;

            case 'checkbox':
            case 'acceptance':
                $terms = '';
                if ($type === 'acceptance' && !empty($f['terms_url'])) {
                    $terms = ' <a href="' . $e(self::localUrl((string) $f['terms_url'])) . '" target="_blank" rel="noopener">' . $e(Text::_('UXS_FORMS_FE_TERMS')) . '</a>';
                }
                $out .= '<label class="uxs-form__choice" for="' . $fid . '"><input type="checkbox" value="1"' . $common . '> <span>' . $labelText . $star . $terms . '</span></label>';
                break;

            case 'file':
                $exts = array_map(static fn ($x) => '.' . $x, (array) ($f['accept'] ?? Fields::FILE_DEFAULT));
                $out .= '<label for="' . $fid . '">' . $labelText . $star . '</label><input type="file"' . $common . ' accept="' . $e(implode(',', $exts)) . '"'
                    . (!empty($f['multiple']) ? ' multiple' : '') . ' data-max-mb="' . (int) ($f['max_size_mb'] ?? 2) . '">'
                    . '<small class="uxs-form__hint">' . $e(Text::sprintf('UXS_FORMS_FE_FILE_HINT', implode(', ', (array) ($f['accept'] ?? [])), (int) ($f['max_size_mb'] ?? 2))) . '</small>';
                break;

            default: // text, email, tel, url, number, date
                $attrs = '';
                if ($type === 'email') {
                    $attrs .= ' autocomplete="email"';
                } elseif ($type === 'tel') {
                    $attrs .= ' autocomplete="tel"';
                }
                if ($type === 'number') {
                    $attrs .= (isset($f['min']) ? ' min="' . $e((string) $f['min']) . '"' : '') . (isset($f['max']) ? ' max="' . $e((string) $f['max']) . '"' : '') . ' step="any"';
                }
                if (!\in_array($type, ['number', 'date'], true)) {
                    $attrs .= (!empty($f['minlength']) ? ' minlength="' . (int) $f['minlength'] . '"' : '') . (!empty($f['maxlength']) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '');
                }
                $out .= '<label for="' . $fid . '">' . $labelText . $star . '</label><input type="' . $e($type === 'text' ? 'text' : $type) . '"' . $common . $attrs
                    . ($f['placeholder'] !== '' ? ' placeholder="' . $e($f['placeholder']) . '"' : '') . ' value="' . $e((string) $f['default_value']) . '">';
        }

        if ($f['help'] !== '') {
            $out .= '<p class="uxs-form__help" id="' . $fid . '_help">' . $e((string) $f['help']) . '</p>';
        }
        $out .= '<p class="uxs-form__error" id="' . $fid . '_err" role="alert" hidden></p></div>';

        return $out;
    }
}

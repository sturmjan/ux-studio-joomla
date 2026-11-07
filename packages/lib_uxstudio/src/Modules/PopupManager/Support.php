<?php

namespace Ux1\UxStudio\Modules\PopupManager;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Helpers shared by the popup-manager and exit-popup modules: HTML / URL / media sanitising, site time zone
 * conversion, menu item lookup, targeting match and the signed preview link.
 */
final class Support
{
    private const HTML_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'h5', 'blockquote', 'span', 'div', 'img', 'hr', 'small', 'sup', 'sub'];
    private const HTML_ATTRS = ['href', 'target', 'rel', 'src', 'alt', 'width', 'height', 'title'];

    /** Plain single-line text (no markup), trimmed and limited. */
    public static function text(mixed $value, int $max): string
    {
        $value = \is_scalar($value) ? (string) $value : '';
        $value = trim(strip_tags(str_replace("\0", '', $value)));

        return mb_substr($value, 0, $max);
    }

    /** Multi-line plain text (one entry per line). */
    public static function lines(mixed $value, int $max): string
    {
        $value = \is_scalar($value) ? (string) $value : '';
        $value = trim(strip_tags(str_replace(["\0", "\r"], '', $value)));

        return mb_substr($value, 0, $max);
    }

    /** Rich text for the front end: only a whitelist of tags and attributes, no script URLs. */
    public static function html(mixed $value, int $max = 20000): string
    {
        $value = \is_scalar($value) ? (string) $value : '';
        $value = mb_substr(str_replace("\0", '', $value), 0, $max);
        if (trim($value) === '') {
            return '';
        }
        // tagsMethod 0 / attrMethod 0 = only the listed tags / attributes survive.
        $filter = new InputFilter(self::HTML_TAGS, self::HTML_ATTRS, 0, 0);
        $clean  = (string) $filter->clean($value, 'html');

        // Belt and braces: drop script-like URL schemes in href / src, force a safe rel on new-tab links.
        $clean = preg_replace_callback(
            '#\s(href|src)\s*=\s*(["\'])(.*?)\2#is',
            static function (array $m): string {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = preg_replace('/[\x00-\x20]+/', '', $url) ?? '';
                if (preg_match('#^(javascript|vbscript|data):#i', $url)) {
                    return '';
                }

                return $m[0];
            },
            $clean
        ) ?? '';
        // New-tab links always get a safe rel (a hand-written rel="opener" must not survive).
        $clean = preg_replace_callback(
            '#<a\s([^>]*)>#i',
            static function (array $m): string {
                if (!preg_match('#target\s*=\s*["\']_blank["\']#i', $m[1])) {
                    return $m[0];
                }
                $attrs = preg_replace('#\srel\s*=\s*(["\']).*?\1#is', '', ' ' . $m[1]) ?? $m[1];

                return '<a rel="noopener noreferrer"' . rtrim($attrs) . '>';
            },
            $clean
        ) ?? $clean;

        return $clean;
    }

    /** A link target: "#", a site relative path, http(s), mailto: or tel:. Anything else returns ''. */
    public static function url(mixed $value): string
    {
        $value = \is_scalar($value) ? trim(str_replace(["\0", "\r", "\n"], '', (string) $value)) : '';
        if ($value === '' || $value === '#') {
            return $value;
        }
        if (mb_strlen($value) > 500) {
            return '';
        }
        if (preg_match('#^/(?!/)[^\s"<>]*$#', $value) || preg_match('#^(mailto|tel):[^\s"<>]+$#i', $value)) {
            return $value;
        }
        if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return '';
    }

    /** Joomla media field value (images/foo.jpg#joomlaImage://...) or http(s) URL to a public image URL; '' when unusable. */
    public static function mediaUrl(mixed $value): string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';
        $value = explode('#', $value, 2)[0];
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) && preg_match('#\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$#i', $value) ? $value : '';
        }
        $value = ltrim(str_replace('\\', '/', $value), '/');
        if (str_contains($value, '..') || str_contains($value, ':') || !preg_match('#\.(jpe?g|png|gif|webp|avif|svg)$#i', $value)) {
            return '';
        }

        return Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $value)));
    }

    public static function color(mixed $value, string $fallback): string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : $fallback;
    }

    public static function intIn(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** @param string[] $allowed @return string[] */
    public static function pick(mixed $value, array $allowed): array
    {
        $list = array_map('strval', array_filter((array) $value, 'is_scalar'));

        return array_values(array_unique(array_intersect($list, $allowed)));
    }

    /** @return int[] positive ids */
    public static function ids(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }

        return array_values($out);
    }

    // ---- time -----------------------------------------------------------

    public static function zone(): \DateTimeZone
    {
        $offset = (string) Factory::getApplication()->get('offset', 'UTC');
        try {
            return new \DateTimeZone($offset !== '' ? $offset : 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** "2026-11-06T10:30" (site time zone) => "2026-11-06 09:30:00" (UTC) or null. */
    public static function localToUtc(mixed $value): ?string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::\d{2})?$/', $value, $m)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($m[1] . ' ' . $m[2] . ':00', self::zone());

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** UTC SQL datetime => "2026-11-06T10:30" in the site time zone ('' for null). */
    public static function utcToLocal(?string $value): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(self::zone())->format('Y-m-d\TH:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Unix timestamp of a site-time "YYYY-MM-DDTHH:MM" value, 0 when empty / invalid. */
    public static function localToTimestamp(mixed $value): int
    {
        $utc = self::localToUtc($value);

        return $utc ? (int) strtotime($utc . ' UTC') : 0;
    }

    // ---- menu / targeting ------------------------------------------------

    /** @return array<string,string> published site menu items as id => label */
    public static function menuOptions(DatabaseInterface $db): array
    {
        $query = $db->createQuery()
            ->select([$db->quoteName('m.id'), $db->quoteName('m.title'), $db->quoteName('m.level'), $db->quoteName('t.title', 'menu')])
            ->from($db->quoteName('#__menu', 'm'))
            ->join('LEFT', $db->quoteName('#__menu_types', 't') . ' ON ' . $db->quoteName('t.menutype') . ' = ' . $db->quoteName('m.menutype') . ' AND ' . $db->quoteName('t.client_id') . ' = 0')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->where($db->quoteName('m.published') . ' = 1')
            ->where($db->quoteName('m.id') . ' > 1')
            ->where($db->quoteName('m.type') . ' IN (' . $db->quote('component') . ', ' . $db->quote('alias') . ')')
            ->order($db->quoteName('t.title') . ', ' . $db->quoteName('m.lft'));
        $out = [];
        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $out[(string) $row['id']] = str_repeat('- ', max(0, (int) $row['level'] - 1)) . $row['title'] . ($row['menu'] ? ' (' . $row['menu'] . ')' : '');
        }

        return $out;
    }

    /**
     * Id of the menu item the current page belongs to, 0 when there is none. Joomla falls back to the home item for
     * pages without their own menu item (login, component URLs ...); such a page is not "the home page", so the item
     * only counts when its component is the one rendering the request.
     */
    public static function activeMenuItem(\Joomla\CMS\Application\CMSApplicationInterface $app): int
    {
        $active = $app->getMenu()?->getActive();
        if (!$active) {
            return 0;
        }
        $input  = $app->getInput();
        $option = (string) ($active->query['option'] ?? '');
        if ($option !== '' && $option !== (string) $input->getCmd('option')) {
            return 0;
        }
        // The default (home) item is what Joomla falls back to for pages without an item of their own, so for it the
        // request must really be that item's page: same view and id (Itemid is no help, Joomla sets it from the fallback).
        if (!empty($active->home)) {
            $view = (string) ($active->query['view'] ?? '');
            if ($view !== '' && $view !== (string) $input->getCmd('view')) {
                return 0;
            }
            if (isset($active->query['id']) && (int) $active->query['id'] !== $input->getInt('id')) {
                return 0;
            }
        }

        return (int) $active->id;
    }

    /**
     * Does the current page satisfy a menu item / URL fragment rule?
     *
     * @param string[] $patterns URL fragments (matched against path + query)
     * @param int[]    $items    menu item ids
     */
    public static function pageMatches(string $mode, array $items, array $patterns, int $activeItem, string $uri): bool
    {
        $patterns = array_values(array_filter(array_map('trim', $patterns), static fn (string $p): bool => $p !== ''));
        if (($mode !== 'include' && $mode !== 'exclude') || (!$items && !$patterns)) {
            return true;
        }
        $hit = $activeItem > 0 && \in_array($activeItem, $items, true);
        if (!$hit) {
            foreach ($patterns as $pattern) {
                if (stripos($uri, $pattern) !== false) {
                    $hit = true;
                    break;
                }
            }
        }

        return $mode === 'include' ? $hit : !$hit;
    }

    /** @return string[] */
    public static function splitLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text))), static fn (string $l): bool => $l !== ''));
    }

    // ---- preview link ----------------------------------------------------

    private static function previewSignature(string $scope, int $id, int $expires): string
    {
        return hash_hmac('sha256', 'uxs-preview|' . $scope . '|' . $id . '|' . $expires, (string) Factory::getApplication()->get('secret', ''));
    }

    /** Front-end URL that shows one popup immediately for the next hour, regardless of targeting. */
    public static function previewUrl(string $scope, int $id): string
    {
        $expires = time() + 3600;

        return Uri::root() . 'index.php?' . http_build_query(['uxs_preview' => $scope, 'uxs_id' => $id, 'uxs_exp' => $expires, 'uxs_sig' => self::previewSignature($scope, $id, $expires)]);
    }

    /** Returns the previewed id for this request or 0 (valid signature, not expired). */
    public static function previewRequested(string $scope): int
    {
        $input = Factory::getApplication()->getInput();
        if ($input->getString('uxs_preview') !== $scope) {
            return 0;
        }
        $id      = $input->getInt('uxs_id');
        $expires = $input->getInt('uxs_exp');
        $sig     = $input->getString('uxs_sig');
        if ($expires < time() || $sig === '' || !hash_equals(self::previewSignature($scope, $id, $expires), $sig)) {
            return 0;
        }

        return $id > 0 ? $id : 1;
    }

    /** Is this a regular page view in which a popup may appear? */
    public static function isPageView(): bool
    {
        $app = Factory::getApplication();
        if (!$app->isClient('site')) {
            return false;
        }
        $doc   = $app->getDocument();
        $input = $app->getInput();

        return $doc instanceof \Joomla\CMS\Document\HtmlDocument
            && (string) $input->getCmd('tmpl') !== 'component'
            && (string) $input->getCmd('print') === ''
            && strtoupper((string) $input->server->get('REQUEST_METHOD', 'GET', 'string')) === 'GET';
    }
}

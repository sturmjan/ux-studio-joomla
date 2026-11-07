<?php

namespace Ux1\UxStudio\Modules\NoticeBoard;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Public side of the Notice Board: the board itself ({uxs-notice-board} in content), document detail, filters,
 * pagination, subscribe form, RSS 2.0 feed and the national open-data document "Informace na úřední desce" (OFN, JSON-LD).
 */
final class Frontend
{
    private const VARS = ['nb_tab', 'nb_q', 'nb_cat', 'nb_from', 'nb_to', 'nb_page', 'dokument'];

    public function __construct(private Store $store, private ModuleContext $ctx)
    {
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function date(string $ymd): string
    {
        // Midday UTC without time zone conversion keeps the calendar day intact.
        return HTMLHelper::_('date', $ymd . ' 12:00:00', Text::_('DATE_FORMAT_LC3'), false);
    }

    /* ---------------------------------------------------------------- URLs */

    /** URL of the page that hosts the board: setting, else the first published article containing the tag, else the home page. */
    public function boardUrl(): string
    {
        $custom = trim((string) $this->ctx->setting('board_url', ''));
        if ($custom !== '') {
            return preg_match('#^https?://#i', $custom) ? $custom : rtrim($this->store->root(), "/") . '/' . ltrim($custom, '/');
        }
        $db    = $this->ctx->db();
        $like1 = '%{uxs-notice-board%';
        $like2 = '%{uxstudio_notice_board%';
        try {
            $id = (int) $db->setQuery(
                $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__content'))
                    ->where($db->quoteName('state') . ' = 1')
                    ->where('(' . $db->quoteName('introtext') . ' LIKE :a OR ' . $db->quoteName('introtext') . ' LIKE :b OR ' . $db->quoteName('fulltext') . ' LIKE :c OR ' . $db->quoteName('fulltext') . ' LIKE :d)')
                    ->bind(':a', $like1)->bind(':b', $like2)->bind(':c', $like1)->bind(':d', $like2)->order($db->quoteName('id') . ' ASC'),
                0,
                1
            )->loadResult();
        } catch (\Throwable $e) {
            $id = 0;
        }

        return $id > 0 ? $this->store->root() . 'index.php?option=com_content&view=article&id=' . $id : $this->store->root();
    }

    /** Current page URL without the board's own query variables. */
    private function currentBase(): string
    {
        $uri = clone Uri::getInstance();
        foreach (self::VARS as $v) {
            $uri->delVar($v);
        }

        return $uri->toString(['scheme', 'host', 'port', 'path', 'query']);
    }

    /** @param array<string,mixed> $vars */
    private function link(string $base, array $vars): string
    {
        $uri = new Uri($base);
        foreach ($vars as $k => $v) {
            if ($v !== '' && $v !== [] && $v !== null) {
                $uri->setVar($k, $v);
            }
        }

        return $uri->toString();
    }

    public function noticeUrl(array $notice): string
    {
        return $this->link($this->boardUrl(), ['dokument' => (int) $notice['id']]);
    }

    /* ---------------------------------------------------------------- board */

    /** @param array<string,string> $atts category, limit, subscribe, filters */
    public function render(array $atts): string
    {
        $this->store->archiveExpired();
        $this->ctx->useStyle('notice-board.css');

        $input = $this->ctx->app()->getInput();
        $base  = $this->currentBase();
        if (($detail = $input->getInt('dokument', 0)) > 0) {
            return $this->renderDetail($detail, $base);
        }

        $fixed   = Store::slug((string) ($atts['category'] ?? ''));
        $perPage = max(1, min(100, (int) ($atts['limit'] ?? 0) ?: (int) $this->ctx->setting('per_page', 20)));
        $filters = ($atts['filters'] ?? '1') === '1';
        $names   = $this->store->categoryNames();

        $archived = $filters && $input->getCmd('nb_tab', '') === 'archive';
        $search   = $filters ? trim((string) $input->getString('nb_q', '')) : '';
        $selected = [];
        if ($fixed !== '') {
            $selected = [$fixed];
        } elseif ($filters) {
            $raw      = $input->get('nb_cat', [], 'array');
            $selected = array_values(array_filter(array_map(static fn ($s): string => Store::slug((string) (\is_scalar($s) ? $s : '')), \is_array($raw) ? $raw : [])));
        }
        $from = $filters ? (Store::cleanDate($input->getString('nb_from', '')) ?? '') : '';
        $to   = $filters ? (Store::cleanDate($input->getString('nb_to', '')) ?? '') : '';
        $page = max(1, $input->getInt('nb_page', 1));

        $result = $this->store->queryPublic([
            'archived' => $archived, 'search' => $search, 'categories' => $selected, 'date_from' => $from, 'date_to' => $to, 'page' => $page, 'per_page' => $perPage,
        ]);
        $counts = $this->store->countPublic();
        $e      = [self::class, 'e'];

        $html = '<div class="uxs-notice-board">';
        if ($filters) {
            $html .= '<nav class="uxs-nb-tabs" aria-label="' . $e(Text::_('UXS_NOTICE_BOARD_ARIA_BOARD')) . '">'
                . '<a class="uxs-nb-tab' . ($archived ? '' : ' is-active') . '" href="' . $e($base) . '"' . ($archived ? '' : ' aria-current="page"') . '>' . $e(Text::_('UXS_NOTICE_BOARD_ACTIVE')) . ' <span class="uxs-nb-tab__count">' . $counts['active'] . '</span></a>'
                . '<a class="uxs-nb-tab' . ($archived ? ' is-active' : '') . '" href="' . $e($this->link($base, ['nb_tab' => 'archive'])) . '"' . ($archived ? ' aria-current="page"' : '') . '>' . $e(Text::_('UXS_NOTICE_BOARD_ARCHIVE')) . ' <span class="uxs-nb-tab__count">' . $counts['archived'] . '</span></a>'
                . '</nav>';

            $html .= '<form class="uxs-nb-filters" method="get" action="' . $e((string) strtok($base, '?')) . '" role="search">';
            foreach ((new Uri($base))->getQuery(true) as $k => $v) {
                if (\is_scalar($v)) {
                    $html .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
                }
            }
            if ($archived) {
                $html .= '<input type="hidden" name="nb_tab" value="archive">';
            }
            $html .= '<input type="search" name="nb_q" value="' . $e($search) . '" placeholder="' . $e(Text::_('UXS_NOTICE_BOARD_SEARCH')) . '" aria-label="' . $e(Text::_('UXS_NOTICE_BOARD_SEARCH')) . '">';
            if ($fixed === '' && $names !== []) {
                $html .= '<details class="uxs-nb-types"><summary>' . $e(Text::_('UXS_NOTICE_BOARD_DOC_TYPE')) . ($selected ? ' (' . \count($selected) . ')' : '') . '</summary><div class="uxs-nb-types__list">';
                foreach ($names as $slug => $name) {
                    $html .= '<label><input type="checkbox" name="nb_cat[]" value="' . $e((string) $slug) . '"' . (\in_array($slug, $selected, true) ? ' checked' : '') . '> ' . $e($name) . '</label>';
                }
                $html .= '</div></details>';
            }
            $html .= '<label>' . $e(Text::_('UXS_NOTICE_BOARD_FROM')) . ' <input type="date" name="nb_from" value="' . $e($from) . '"></label>'
                . '<label>' . $e(Text::_('UXS_NOTICE_BOARD_TO')) . ' <input type="date" name="nb_to" value="' . $e($to) . '"></label>'
                . '<button type="submit">' . $e(Text::_('UXS_NOTICE_BOARD_FILTER')) . '</button>';
            if ($search !== '' || ($selected && $fixed === '') || $from !== '' || $to !== '') {
                $html .= '<a class="uxs-nb-reset" href="' . $e($archived ? $this->link($base, ['nb_tab' => 'archive']) : $base) . '">' . $e(Text::_('UXS_NOTICE_BOARD_RESET')) . '</a>';
            }
            $html .= '</form>';
        }

        if ($result['items'] === []) {
            $html .= '<p class="uxs-nb-empty">' . $e(Text::_('UXS_NOTICE_BOARD_EMPTY')) . '</p>';
        } else {
            $html .= '<ul class="uxs-nb-list">';
            foreach ($result['items'] as $n) {
                $html .= '<li class="uxs-nb-item"><h3 class="uxs-nb-item__title"><a href="' . $e($this->link($base, ['dokument' => $n['id']])) . '">' . $e($n['title']) . '</a></h3>'
                    . $this->meta($n, $names, false)
                    . ($n['body'] !== '' ? '<div class="uxs-nb-item__body">' . $n['body'] . '</div>' : '')
                    . $this->files($n) . '</li>';
            }
            $html .= '</ul>';
            if ($result['pages'] > 1) {
                $keep = array_filter(['nb_tab' => $archived ? 'archive' : '', 'nb_q' => $search, 'nb_cat' => $fixed === '' ? $selected : [], 'nb_from' => $from, 'nb_to' => $to]);
                $html .= '<nav class="uxs-nb-pager" aria-label="' . $e(Text::_('UXS_NOTICE_BOARD_ARIA_PAGES')) . '">';
                for ($i = 1; $i <= $result['pages']; $i++) {
                    $cur   = $i === $result['page'];
                    $html .= '<a class="uxs-nb-pager__link' . ($cur ? ' is-active' : '') . '" href="' . $e($this->link($base, $keep + ($i > 1 ? ['nb_page' => $i] : []))) . '"' . ($cur ? ' aria-current="page"' : '') . '>' . $i . '</a>';
                }
                $html .= '</nav>';
            }
        }

        $site  = rtrim($this->store->root(), "/");
        $html .= '<p class="uxs-nb-links"><a href="' . $e($site . '/index.php?uxs_nb=open-data') . '" target="_blank" rel="noopener">' . $e(Text::_('UXS_NOTICE_BOARD_OPEN_DATA')) . '</a>'
            . '<a href="' . $e($site . '/index.php?uxs_nb=feed' . ($fixed !== '' ? '&cat=' . rawurlencode($fixed) : '')) . '" target="_blank" rel="noopener">RSS</a></p>';
        if (($atts['subscribe'] ?? '1') === '1') {
            $html .= $this->subscribeForm();
        }

        return $html . '</div>';
    }

    private function renderDetail(int $id, string $base): string
    {
        $e      = [self::class, 'e'];
        $notice = $this->store->getPublicNotice($id);
        $html   = '<div class="uxs-notice-board"><a class="uxs-nb-back" href="' . $e($base) . '">&larr; ' . $e(Text::_('UXS_NOTICE_BOARD_BACK')) . '</a>';
        if ($notice === null) {
            return $html . '<p class="uxs-nb-empty">' . $e(Text::_('UXS_NOTICE_BOARD_NOT_FOUND')) . '</p></div>';
        }
        $html .= '<article class="uxs-nb-detail"><h2 class="uxs-nb-item__title">' . $e($notice['title']) . '</h2>'
            . $this->meta($notice, $this->store->categoryNames(), true)
            . ($notice['body'] !== '' ? '<div class="uxs-nb-item__body">' . $notice['body'] . '</div>' : '')
            . $this->files($notice);
        if ($notice['is_archived']) {
            $html .= '<p class="uxs-nb-archived" role="status">' . $e(Text::_('UXS_NOTICE_BOARD_ARCHIVED_NOTE')) . '</p>';
        }

        return $html . '</article></div>';
    }

    /** @param array<string,string> $names */
    private function meta(array $n, array $names, bool $full): string
    {
        $e    = [self::class, 'e'];
        $html = '<p class="uxs-nb-item__meta">';
        if ($n['publish_date'] !== '') {
            $html .= '<span>' . ($full ? $e(Text::_('UXS_NOTICE_BOARD_POSTED')) . ': ' : '') . $e(self::date($n['publish_date'])) . '</span>';
        }
        if ($full && $n['expiry_date'] !== '') {
            $html .= '<span>' . $e(Text::_('UXS_NOTICE_BOARD_REMOVED')) . ': ' . $e(self::date($n['expiry_date'])) . '</span>';
        }
        if ($n['reference'] !== '') {
            $html .= '<span class="uxs-nb-item__ref">' . $e($n['reference']) . '</span>';
        }
        if ($n['category'] !== '') {
            $html .= '<span class="uxs-nb-item__cat">' . $e($names[$n['category']] ?? $n['category']) . '</span>';
        }

        return $html . '</p>';
    }

    private function files(array $n): string
    {
        $files = array_filter((array) $n['attachments'], static fn (array $f): bool => $f['url'] !== '');
        if ($files === []) {
            return '';
        }
        $html = '<ul class="uxs-nb-item__files">';
        foreach ($files as $f) {
            $html .= '<li><a href="' . self::e($f['url']) . '" rel="noopener" target="_blank">' . self::e($f['filename']) . '</a>'
                . ($f['size'] > 0 ? ' <span class="uxs-nb-item__size">(' . self::e(HTMLHelper::_('number.bytes', $f['size'])) . ')</span>' : '') . '</li>';
        }

        return $html . '</ul>';
    }

    private function subscribeForm(): string
    {
        $e  = [self::class, 'e'];
        $id = 'uxs-nb-sub-' . substr(md5(uniqid('', true)), 0, 6);
        $this->ctx->useScript('notice-board.js');

        return '<form class="uxs-nb-subscribe" id="' . $id . '" method="post" action="' . $e($this->store->root() . 'index.php?uxs_nb=subscribe') . '" data-endpoint="' . $e($this->store->root() . 'index.php?option=com_uxstudio&task=public.dispatch&format=json&route=notice-board/subscribe') . '"'
            . ' data-sending="' . $e(Text::_('UXS_NOTICE_BOARD_SENDING')) . '" data-ok="' . $e(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_OK')) . '" data-error="' . $e(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_ERROR')) . '">'
            . '<label for="' . $id . '-email">' . $e(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_LABEL')) . '</label><br>'
            . '<input type="email" id="' . $id . '-email" name="email" required maxlength="190" placeholder="' . $e(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE_PLACEHOLDER')) . '"> '
            . '<button type="submit">' . $e(Text::_('UXS_NOTICE_BOARD_SUBSCRIBE')) . '</button>'
            . HTMLHelper::_('form.token') . '<div class="uxs-nb-subscribe__msg" role="status" aria-live="polite"></div></form>';
    }

    /* ---------------------------------------------------------------- RSS and open data */

    public function rss(string $category, int $limit): string
    {
        $this->store->archiveExpired();
        $app    = $this->ctx->app();
        $result = $this->store->queryPublic(['archived' => false, 'categories' => $category !== '' ? [Store::slug($category)] : [], 'per_page' => $limit]);
        $site   = rtrim($this->store->root(), "/") . '/';
        $title  = (string) $app->get('sitename', '') . ' - ' . Text::_('UXS_NOTICE_BOARD_FEED_TITLE');
        $x      = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            . '<title>' . $x($title) . '</title><link>' . $x($this->boardUrl()) . '</link><description>' . $x(Text::_('UXS_NOTICE_BOARD_FEED_DESC')) . '</description>'
            . '<lastBuildDate>' . gmdate('r') . '</lastBuildDate>';
        foreach ($result['items'] as $n) {
            $date = strtotime(($n['publish_date'] !== '' ? $n['publish_date'] : substr($n['created_at'], 0, 10)) . ' 12:00:00 UTC') ?: time();
            $xml .= '<item><title>' . $x($n['title']) . '</title><link>' . $x($this->noticeUrl($n)) . '</link><guid isPermaLink="false">uxstudio-notice-' . (int) $n['id'] . '</guid>'
                . '<pubDate>' . gmdate('r', $date) . '</pubDate>'
                . '<description>' . $x($n['body']) . '</description>';
            if ($n['category'] !== '') {
                $xml .= '<category>' . $x($n['category']) . '</category>';
            }
            foreach ($n['attachments'] as $f) {
                if ($f['url'] !== '') {
                    $xml .= '<enclosure url="' . $x($f['abs_url']) . '" length="' . (int) $f['size'] . '" type="' . $x($f['mime']) . '"/>';
                }
            }
            $xml .= '</item>';
        }

        return $xml . '</channel></rss>';
    }

    /**
     * Open data "Informace na úřední desce" (OFN Úřední deska, JSON-LD) of all published, not archived notices.
     * Structure and keys are fixed by the standard (Czech), the same as the WordPress version outputs.
     *
     * @return array<string,mixed>
     */
    public function openData(): array
    {
        $this->store->archiveExpired();
        $names = $this->store->categoryNames();
        $items = [];
        for ($p = 1; $p <= 5; $p++) {
            $res   = $this->store->queryPublic(['archived' => false, 'per_page' => 100, 'page' => $p]);
            $items = array_merge($items, $res['items']);
            if ($p >= $res['pages']) {
                break;
            }
        }
        $info   = [];
        foreach ($items as $n) {
            $entry = [
                'typ'      => ['Digitální objekt', 'Informace na úřední desce'],
                'url'      => $this->noticeUrl($n),
                'název'    => ['cs' => $n['title']],
                'vyvěšení' => ['typ' => 'Časový okamžik', 'datum' => $n['publish_date'] !== '' ? $n['publish_date'] : substr($n['created_at'], 0, 10)],
            ];
            if ($n['expiry_date'] !== '') {
                $entry['relevantní_do'] = ['typ' => 'Časový okamžik', 'datum' => $n['expiry_date']];
            }
            if ($n['reference'] !== '') {
                $entry['číslo_jednací'] = $n['reference'];
            }
            if ($n['category'] !== '') {
                $entry['agenda'] = [['typ' => 'Agenda', 'název' => ['cs' => $names[$n['category']] ?? $n['category']]]];
            }
            $docs = [];
            foreach ($n['attachments'] as $f) {
                if ($f['url'] !== '') {
                    $docs[] = [
                        'typ'       => 'Digitální objekt',
                        'url'       => $f['abs_url'],
                        'název'     => ['cs' => $f['filename']],
                        'typ_média' => 'http://www.iana.org/assignments/media-types/' . ($f['mime'] !== '' ? $f['mime'] : 'application/octet-stream'),
                    ];
                }
            }
            if ($docs !== []) {
                $entry['dokument'] = $docs;
            }
            $info[] = $entry;
        }

        return [
            '@context'     => 'https://ofn.gov.cz/úřední-desky/2021-07-20/kontexty/úřední-deska.jsonld',
            'typ'          => 'Úřední deska',
            'stránka'      => $this->boardUrl(),
            'provozovatel' => ['typ' => 'Orgán veřejné moci', 'název' => ['cs' => (string) $this->ctx->app()->get('sitename', '')]],
            'informace'    => $info,
        ];
    }
}

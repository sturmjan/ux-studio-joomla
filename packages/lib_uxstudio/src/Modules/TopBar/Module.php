<?php

namespace Ux1\UxStudio\Modules\TopBar;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Top Bar: a scheduled announcement bar injected right after <body>. One simple text (setting "content") plus a list of
 * further messages (table #__uxstudio_top_bar_slides) that rotate like a slideshow, each with its own schedule.
 * Colours are CSS custom properties (also taken from the template on request), the bar can be dismissed by visitors
 * (cookie) and is CSP friendly: no inline script, the colours go through WebAssetManager inline style.
 */
final class Module extends BaseModule
{
    /** Longest message source (characters) accepted before filtering, so the HTML is never cut in the middle of a tag. */
    private const MAX_TEXT = 5000;

    public const COOKIE = 'uxs_tb_dismiss';

    /** @var array<int,array{title:string,text:string}>|null */
    private ?array $activeCache = null;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onBeforeCompileHead', [$this, 'assets']);
            $ctx->on('onAfterRender', [$this, 'inject']);
        }
    }

    /* ---------------------------------------------------------------- settings */

    public function settingsSchema(): array
    {
        return [
            ['key' => 'content', 'type' => 'richtext', 'label' => 'UXS_TOP_BAR_CONTENT', 'help' => 'UXS_TOP_BAR_CONTENT_HELP', 'default' => '', 'maxlength' => 5000],
            ['key' => 'interval', 'type' => 'number', 'label' => 'UXS_TOP_BAR_INTERVAL', 'help' => 'UXS_TOP_BAR_INTERVAL_HELP', 'default' => 6, 'min' => 2, 'max' => 60],
            [
                'key'     => 'color_mode',
                'type'    => 'select',
                'label'   => 'UXS_TOP_BAR_COLOR_MODE',
                'help'    => 'UXS_TOP_BAR_COLOR_MODE_HELP',
                'options' => ['custom' => 'UXS_TOP_BAR_COLOR_CUSTOM', 'template' => 'UXS_TOP_BAR_COLOR_TEMPLATE'],
                'default' => 'custom',
            ],
            ['key' => 'bg_color', 'type' => 'color', 'label' => 'UXS_TOP_BAR_BG', 'help' => 'UXS_TOP_BAR_BG_HELP', 'default' => '#1d3998'],
            ['key' => 'text_color', 'type' => 'color', 'label' => 'UXS_TOP_BAR_FG', 'help' => 'UXS_TOP_BAR_FG_HELP', 'default' => '#ffffff'],
            ['key' => 'dismissible', 'type' => 'toggle', 'label' => 'UXS_TOP_BAR_DISMISSIBLE', 'help' => 'UXS_TOP_BAR_DISMISSIBLE_HELP', 'default' => false],
            [
                'key'     => 'dismiss_days',
                'type'    => 'number',
                'label'   => 'UXS_TOP_BAR_DISMISS_DAYS',
                'help'    => 'UXS_TOP_BAR_DISMISS_DAYS_HELP',
                'default' => 7,
                'min'     => 0,
                'max'     => 365,
                'show_if' => ['key' => 'dismissible', 'truthy' => true],
            ],
            ['key' => 'sticky', 'type' => 'toggle', 'label' => 'UXS_TOP_BAR_STICKY', 'help' => 'UXS_TOP_BAR_STICKY_HELP', 'default' => false],
            ['key' => 'schedule_publish', 'type' => 'datetime', 'label' => 'UXS_TOP_BAR_PUBLISH', 'help' => 'UXS_TOP_BAR_PUBLISH_HELP', 'default' => ''],
            ['key' => 'schedule_deactivate', 'type' => 'datetime', 'label' => 'UXS_TOP_BAR_DEACTIVATE', 'help' => 'UXS_TOP_BAR_DEACTIVATE_HELP', 'default' => ''],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_top_bar_slides` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `published` TINYINT(1) NOT NULL DEFAULT 1, '
                . '`mode` VARCHAR(16) NOT NULL DEFAULT \'text\', `title` VARCHAR(190) NOT NULL DEFAULT \'\', `body` MEDIUMTEXT NULL, '
                . '`from_at` VARCHAR(16) NOT NULL DEFAULT \'\', `to_at` VARCHAR(16) NOT NULL DEFAULT \'\', '
                . '`ordering` INT NOT NULL DEFAULT 0, `created` DATETIME NOT NULL, `modified` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_published` (`published`, `ordering`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    /* ---------------------------------------------------------------- scheduling */

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $this->ctx()->app()->get('offset', 'UTC'));
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Whether a from/to window (site time, "" = open) contains now. */
    public function inWindow(string $from, string $to): bool
    {
        $tz  = $this->timezone();
        $now = new \DateTimeImmutable('now', $tz);
        foreach ([[$from, true], [$to, false]] as [$value, $isFrom]) {
            if ($value === '') {
                continue;
            }
            try {
                $limit = new \DateTimeImmutable($value, $tz);
            } catch (\Throwable $e) {
                continue;
            }
            if ($isFrom ? $now < $limit : $now >= $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Messages to show right now: the simple bar text first, then the published messages whose own schedule contains
     * now. Only while the whole bar is inside its own schedule.
     *
     * @return array<int,array{title:string,text:string}>
     */
    public function activeSlides(): array
    {
        if ($this->activeCache !== null) {
            return $this->activeCache;
        }
        $ctx = $this->ctx();
        $out = [];
        if ($this->inWindow((string) $ctx->setting('schedule_publish', ''), (string) $ctx->setting('schedule_deactivate', ''))) {
            $content = (string) $ctx->setting('content', '');
            if (trim(strip_tags($content)) !== '') {
                $out[] = ['title' => '', 'text' => $content];
            }
            foreach ($this->rows(true) as $row) {
                if (trim(strip_tags($row['text'])) === '' || !$this->inWindow($row['from'], $row['to'])) {
                    continue;
                }
                $out[] = ['title' => $row['mode'] === 'title_text' ? trim($row['title']) : '', 'text' => $row['text']];
            }
        }

        return $this->activeCache = $out;
    }

    private function hash(array $slides): string
    {
        return substr(md5((string) json_encode($slides, JSON_UNESCAPED_UNICODE)), 0, 10);
    }

    private function dismissed(array $slides): bool
    {
        if (!$this->ctx()->setting('dismissible', false)) {
            return false;
        }

        return (string) $this->ctx()->app()->getInput()->cookie->get(self::COOKIE, '', 'cmd') === $this->hash($slides);
    }

    /** True for a regular HTML page of the site where the bar belongs. */
    private function isPage(): bool
    {
        $app = $this->ctx()->app();

        return $app->isClient('site')
            && $app->getDocument()?->getType() === 'html'
            && $app->getInput()->getCmd('tmpl', '') !== 'component';
    }

    /* ---------------------------------------------------------------- front-end */

    public function assets(Event $event): void
    {
        if (!$this->isPage()) {
            return;
        }
        $slides = $this->activeSlides();
        if ($slides === [] || $this->dismissed($slides)) {
            return;
        }
        $ctx = $this->ctx();
        $ctx->useStyle('top-bar.css');
        $ctx->useScript('top-bar.js');

        $color = static fn (string $v, string $fallback): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $fallback;
        $bg    = $color((string) $ctx->setting('bg_color', ''), '#1d3998');
        $fg    = $color((string) $ctx->setting('text_color', ''), '#ffffff');
        if ((string) $ctx->setting('color_mode', 'custom') === 'template') {
            $bg = 'var(--cassiopeia-color-primary, var(--primary, ' . $bg . '))';
        }
        $ctx->app()->getDocument()->getWebAssetManager()->addInlineStyle(
            ':root{--uxs-tb-bg:' . $bg . ';--uxs-tb-fg:' . $fg . '}',
            ['name' => 'uxs.top-bar.colors']
        );
    }

    public function inject(Event $event): void
    {
        if (!$this->isPage()) {
            return;
        }
        $slides = $this->activeSlides();
        if ($slides === [] || $this->dismissed($slides)) {
            return;
        }
        $app  = $this->ctx()->app();
        $body = $app->getBody();
        if (!\is_string($body) || !preg_match('/<body\b[^>]*>/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $at = $m[0][1] + \strlen($m[0][0]);
        $app->setBody(substr($body, 0, $at) . "\n" . $this->renderBar($slides) . substr($body, $at));
    }

    /** @param array<int,array{title:string,text:string}> $slides */
    public function renderBar(array $slides): string
    {
        $ctx      = $this->ctx();
        $filter   = InputFilter::getInstance([], [], 1, 1);
        $interval = max(2, min(60, (int) $ctx->setting('interval', 6)));
        $e        = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $attrs    = ' data-interval="' . $interval . '"';
        if (\count($slides) > 1) {
            $attrs .= ' data-slides="' . \count($slides) . '"';
        }
        $dismissible = (bool) $ctx->setting('dismissible', false);
        if ($dismissible) {
            $attrs .= ' data-hash="' . $this->hash($slides) . '" data-days="' . max(0, min(365, (int) $ctx->setting('dismiss_days', 7))) . '"';
        }

        $html = '<div id="uxstudio-top-bar" class="uxstudio-top-bar' . ($ctx->setting('sticky', false) ? ' is-sticky' : '') . '" role="region" aria-label="'
            . $e(Text::_('UXS_TOP_BAR_REGION')) . '"' . $attrs . '><div class="uxstudio-top-bar__track">';
        foreach ($slides as $i => $slide) {
            $html .= '<div class="uxstudio-top-bar__content' . ($i === 0 ? ' is-active' : '') . '">';
            if ($slide['title'] !== '') {
                $html .= '<strong class="uxstudio-top-bar__title">' . $e($slide['title']) . '</strong>';
            }
            $html .= $filter->clean($slide['text'], 'html') . '</div>';
        }
        $html .= '</div>';
        if ($dismissible) {
            $html .= '<button type="button" class="uxstudio-top-bar__close" aria-label="' . $e(Text::_('UXS_TOP_BAR_CLOSE')) . '"><span aria-hidden="true">&times;</span></button>';
        }

        return $html . '</div>';
    }

    /* ---------------------------------------------------------------- data */

    /** @return array<int,array<string,mixed>> */
    private function rows(bool $publishedOnly): array
    {
        $db = $this->ctx()->db();
        try {
            $q = $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_top_bar_slides'));
            if ($publishedOnly) {
                $q->where($db->quoteName('published') . ' = 1');
            }
            $q->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');
            $list = $db->setQuery($q)->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'published' => (bool) $r['published'],
            'mode'      => (string) $r['mode'],
            'title'     => (string) $r['title'],
            'text'      => (string) $r['body'],
            'from'      => (string) $r['from_at'],
            'to'        => (string) $r['to_at'],
            'ordering'  => (int) $r['ordering'],
        ], $list);
    }

    private function find(int $id): ?array
    {
        foreach ($this->rows(false) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------- API */

    public function routes(): array
    {
        return [
            'GET slides'                => [$this, 'listSlides'],
            'POST slides'               => [$this, 'createSlide'],
            'POST slides/{id}'          => [$this, 'updateSlide'],
            'POST slides/{id}/delete'   => ['handler' => [$this, 'deleteSlide'], 'acl' => 'core.admin'],
            'GET preview'               => [$this, 'preview'],
        ];
    }

    public function listSlides(ApiRequest $r): array
    {
        return ['items' => $this->rows(false)];
    }

    /** Whether the site shows a bar right now and how many messages rotate (the markup itself is not exposed). */
    public function preview(ApiRequest $r): array
    {
        $slides = $this->activeSlides();

        return ['active' => $slides !== [], 'count' => \count($slides)];
    }

    public function createSlide(ApiRequest $r): array
    {
        $v  = $this->validate($r->body(), null);
        $db = $this->ctx()->db();
        $now = Factory::getDate()->toSql();
        $max = (int) $db->setQuery($db->createQuery()->select('MAX(' . $db->quoteName('ordering') . ')')->from($db->quoteName('#__uxstudio_top_bar_slides')))->loadResult();
        $order = $max + 1;
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_top_bar_slides'))
                ->columns($db->quoteName(['published', 'mode', 'title', 'body', 'from_at', 'to_at', 'ordering', 'created', 'modified']))
                ->values(':p, :m, :t, :b, :f, :to, :o, :c, :c2')
                ->bind(':p', $v['published'], ParameterType::INTEGER)->bind(':m', $v['mode'])->bind(':t', $v['title'])->bind(':b', $v['text'])
                ->bind(':f', $v['from'])->bind(':to', $v['to'])->bind(':o', $order, ParameterType::INTEGER)->bind(':c', $now)->bind(':c2', $now)
        )->execute();

        return $this->find((int) $db->insertid()) ?? [];
    }

    public function updateSlide(ApiRequest $r): array
    {
        $id  = (int) $r->param('id');
        $old = $this->find($id);
        if ($old === null) {
            throw new ApiException(Text::_('UXS_TOP_BAR_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $v   = $this->validate($r->body(), $old);
        $db  = $this->ctx()->db();
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_top_bar_slides'))
                ->set($db->quoteName('published') . ' = :p')->set($db->quoteName('mode') . ' = :m')->set($db->quoteName('title') . ' = :t')
                ->set($db->quoteName('body') . ' = :b')->set($db->quoteName('from_at') . ' = :f')->set($db->quoteName('to_at') . ' = :to')
                ->set($db->quoteName('ordering') . ' = :o')->set($db->quoteName('modified') . ' = :mod')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':p', $v['published'], ParameterType::INTEGER)->bind(':m', $v['mode'])->bind(':t', $v['title'])->bind(':b', $v['text'])
                ->bind(':f', $v['from'])->bind(':to', $v['to'])->bind(':o', $v['ordering'], ParameterType::INTEGER)->bind(':mod', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $this->find($id) ?? [];
    }

    public function deleteSlide(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if ($this->find($id) === null) {
            throw new ApiException(Text::_('UXS_TOP_BAR_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $db = $this->ctx()->db();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_top_bar_slides'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return ['deleted' => true];
    }

    /**
     * @param array<string,mixed>      $in  request body
     * @param array<string,mixed>|null $old existing row (partial update keeps its values)
     * @return array{published:int,mode:string,title:string,text:string,from:string,to:string,ordering:int}
     */
    private function validate(array $in, ?array $old): array
    {
        $get = static fn (string $k, mixed $d) => \array_key_exists($k, $in) ? $in[$k] : ($old[$k] ?? $d);

        $mode = (string) $get('mode', 'text');
        if (!\in_array($mode, ['text', 'title_text'], true)) {
            $mode = 'text';
        }
        $raw = (string) (\is_scalar($get('text', '')) ? $get('text', '') : '');
        if (mb_strlen($raw) > self::MAX_TEXT) {
            throw new ApiException(Text::sprintf('UXS_TOP_BAR_ERR_TEXT_LONG', self::MAX_TEXT), 422, 'invalid_value');
        }
        $text = InputFilter::getInstance([], [], 1, 1)->clean(\Ux1\UxStudio\Modules\NoticeBoard\Store::dropActiveContent($raw), 'html');
        if (trim(strip_tags($text)) === '') {
            throw new ApiException(Text::_('UXS_TOP_BAR_ERR_TEXT'), 422, 'invalid_value');
        }
        $date = static function (mixed $v): string {
            $v = trim((string) (\is_scalar($v) ? $v : ''));
            if ($v === '') {
                return '';
            }
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $v, $m) || strtotime($m[1] . ' ' . $m[2]) === false) {
                throw new ApiException(Text::_('UXS_TOP_BAR_ERR_DATE'), 422, 'invalid_value');
            }

            return $m[1] . 'T' . $m[2];
        };

        return [
            'published' => filter_var($get('published', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'mode'      => $mode,
            'title'     => mb_substr(trim(strip_tags((string) (\is_scalar($get('title', '')) ? $get('title', '') : ''))), 0, 190),
            'text'      => $text,
            'from'      => $date($get('from', '')),
            'to'        => $date($get('to', '')),
            'ordering'  => (int) $get('ordering', 0),
        ];
    }

    public function dashboardWidget(): ?array
    {
        $on = $this->activeSlides() !== [];

        return [
            'title'  => Text::_('UXS_TOP_BAR_WIDGET_TITLE'),
            'value'  => Text::_($on ? 'UXS_TOP_BAR_WIDGET_VALUE_ON' : 'UXS_TOP_BAR_WIDGET_VALUE_OFF'),
            'text'   => Text::_($on ? 'UXS_TOP_BAR_WIDGET_ON' : 'UXS_TOP_BAR_WIDGET_OFF'),
            'status' => $on ? 'ok' : 'warn',
        ];
    }
}

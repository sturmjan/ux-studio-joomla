<?php

namespace Ux1\UxStudio\Modules\OpeningHours;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * HTML of the front end: location card (name, open/closed badge, announcement, address, weekly table, upcoming
 * exceptions), the inline status badge and the location switcher. All output is escaped; styles and the switcher script
 * are module assets (no inline code), the severity colours go through WebAssetManager inline style.
 */
final class Renderer
{
    public function __construct(private Module $module, private ModuleContext $ctx)
    {
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** @param int|string $location location id, 0 = the first one, 'all' = every location */
    public function front(int|string $location, string $display = 'card', string $cssClass = ''): string
    {
        $all = $this->module->listLocations();
        if ($all === []) {
            return '';
        }
        if ($location === 'all') {
            $set = $all;
        } else {
            $found = null;
            foreach ($all as $l) {
                if ($location === 0 || $l['id'] === (int) $location) {
                    $found = $l;
                    break;
                }
            }
            if ($found === null) {
                return '';
            }
            $set = [$found];
        }
        $this->assets();

        if ($display === 'status') {
            return implode(' ', array_map(fn (array $l): string => $this->badge($l), $set));
        }
        $class = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', $cssClass) ?? '');
        $html  = '';
        $dropdown = \count($set) > 1 && $this->ctx->setting('show_location_dropdown', false);
        if ($dropdown) {
            $id    = 'uxs-oh-select-' . substr(md5(implode(',', array_column($set, 'id'))), 0, 6);
            $html .= '<div class="uxs-oh-multi' . ($class !== '' ? ' ' . self::e($class) : '') . '" data-uxs-oh-multi><label class="uxs-oh__switch" for="' . $id . '">'
                . self::e(Text::_('UXS_OPENING_HOURS_LOCATION')) . ' <select id="' . $id . '">';
            foreach ($set as $l) {
                $html .= '<option value="' . (int) $l['id'] . '">' . self::e($l['title']) . '</option>';
            }
            $html .= '</select></label>';
        }
        foreach ($set as $i => $l) {
            $html .= $this->card($l, $dropdown ? ($i === 0 ? '' : ' is-hidden') : ($class !== '' ? ' ' . self::e($class) : ''));
        }

        return $html . ($dropdown ? '</div>' : '');
    }

    private function assets(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->ctx->useStyle('opening-hours.css');
        if ($this->ctx->setting('show_location_dropdown', false)) {
            $this->ctx->useScript('opening-hours.js');
        }
        $color = static fn (mixed $v, string $d): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : $d;
        $css   = '.uxs-oh__banner--info{background:' . $color($this->ctx->setting('severity_info_color'), '#2271b1')
            . '}.uxs-oh__banner--warning{background:' . $color($this->ctx->setting('severity_warning_color'), '#8a5a00')
            . '}.uxs-oh__banner--critical{background:' . $color($this->ctx->setting('severity_critical_color'), '#d63638') . '}';
        $doc = $this->ctx->app()->getDocument();
        if ($doc instanceof \Joomla\CMS\Document\HtmlDocument) {
            $doc->getWebAssetManager()->addInlineStyle($css, ['name' => 'uxs.opening-hours.colors']);
        }
    }

    private function badge(array $location): string
    {
        $open = $this->module->status($location)['open'];

        return '<span class="uxs-oh__badge ' . ($open ? 'is-open' : 'is-closed') . '">'
            . self::e(Text::_($open ? 'UXS_OPENING_HOURS_OPEN_NOW' : 'UXS_OPENING_HOURS_CLOSED_NOW')) . '</span>';
    }

    private function card(array $l, string $extraClass): string
    {
        $tz      = $this->module->timezone();
        $now     = new \DateTimeImmutable('now', $tz);
        $today   = $now->format('Y-m-d');
        $todayIx = (int) $now->format('N') - 1;
        $status  = $this->module->status($l);

        $html = '<div class="uxs-oh' . $extraClass . '" data-location="' . (int) $l['id'] . '"><div class="uxs-oh__head"><span class="uxs-oh__title">'
            . self::e($l['title']) . '</span>' . $this->badge($l) . '</div>';

        $next = $this->nextText($l, $status['open']);
        if ($next !== '') {
            $html .= '<div class="uxs-oh__next">' . self::e($next) . '</div>';
        }
        $banner = Module::activeAnnouncement($l['announcements'], $today);
        if ($banner !== null) {
            $html .= '<div class="uxs-oh__banner uxs-oh__banner--' . self::e($banner['severity']) . '" role="status">' . self::e($banner['text']) . '</div>';
        }
        if ($l['address'] !== '') {
            $html .= '<div class="uxs-oh__address">' . self::e($l['address']) . '</div>';
        }
        if ($l['phone'] !== '') {
            $tel   = preg_replace('/[^0-9+]/', '', $l['phone']);
            $html .= '<div class="uxs-oh__phone"><a href="tel:' . self::e((string) $tel) . '">' . self::e($l['phone']) . '</a></div>';
        }
        $note = is_array($l['override']) ? ($l['override']['reason'] !== '' ? $l['override']['reason'] : Text::_('UXS_OPENING_HOURS_TEMP_CLOSED')) : $this->module->dayNote($l, $today);
        if ($note !== '') {
            $html .= '<div class="uxs-oh__note">' . self::e($note) . '</div>';
        }

        $html .= '<table class="uxs-oh__table"><caption class="uxs-oh__caption">' . self::e(Text::_('UXS_OPENING_HOURS_TABLE_CAPTION')) . '</caption><tbody>';
        foreach (Module::DAYS as $ix => $day) {
            $ranges = $l['hours'][$day] ?? [];
            $value  = $ranges === [] ? self::e(Text::_('UXS_OPENING_HOURS_CLOSED')) : self::e($this->ranges($ranges));
            $html  .= '<tr' . ($ix === $todayIx ? ' class="is-today"' : '') . '><th scope="row">' . self::e(Text::_('UXS_OPENING_HOURS_DAY_' . strtoupper($day))) . '</th><td>' . $value . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $upcoming = $this->upcoming($l, $now);
        if ($upcoming !== []) {
            $html .= '<div class="uxs-oh__exceptions"><div class="uxs-oh__exceptions-title">' . self::e(Text::_('UXS_OPENING_HOURS_UPCOMING')) . '</div><ul>';
            foreach ($upcoming as $row) {
                $html .= '<li><span>' . self::e($row['date']) . ($row['label'] !== '' ? ' - ' . self::e($row['label']) : '') . '</span><span>' . self::e($row['value']) . '</span></li>';
            }
            $html .= '</ul></div>';
        }

        return $html . '</div>';
    }

    /** @param array<int,array{open:string,close:string}> $ranges */
    private function ranges(array $ranges): string
    {
        return implode(', ', array_map(static fn (array $r): string => $r['open'] . '–' . $r['close'], $ranges));
    }

    /** "Opens today at 08:00" / "Opens Monday at 08:00" / "Closes at 17:00" (the next change of the state). */
    private function nextText(array $l, bool $open): string
    {
        $tz  = $this->module->timezone();
        $now = new \DateTimeImmutable('now', $tz);
        if ($open) {
            $change = $this->module->status($l)['next_change'];

            return $change === null ? '' : Text::sprintf('UXS_OPENING_HOURS_CLOSES_AT', (new \DateTimeImmutable($change))->setTimezone($tz)->format('H:i'));
        }
        $ts = $this->module->nextOpen($l);
        if ($ts === null) {
            return '';
        }
        $at   = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $time = $at->format('H:i');
        if ($at->format('Y-m-d') === $now->format('Y-m-d')) {
            return Text::sprintf('UXS_OPENING_HOURS_OPENS_TODAY', $time);
        }
        if ($at->format('Y-m-d') === $now->modify('+1 day')->format('Y-m-d')) {
            return Text::sprintf('UXS_OPENING_HOURS_OPENS_TOMORROW', $time);
        }

        return Text::sprintf('UXS_OPENING_HOURS_OPENS_DAY', Text::_('UXS_OPENING_HOURS_DAY_' . strtoupper(Module::DAYS[(int) $at->format('N') - 1])), $time);
    }

    /** @return array<int,array{date:string,label:string,value:string}> exceptions and holidays within the next 30 days (max 5) */
    private function upcoming(array $l, \DateTimeImmutable $now): array
    {
        $rows = [];
        for ($i = 1; $i <= 30 && \count($rows) < 5; $i++) {
            $day  = $now->modify('+' . $i . ' day');
            $date = $day->format('Y-m-d');
            $note = $this->module->dayNote($l, $date);
            if ($note === '') {
                continue;
            }
            $ranges = $this->module->dayRanges($l, $date);
            $rows[] = [
                'date'  => HTMLHelper::_('date', $date . ' 12:00:00', Text::_('UXS_OPENING_HOURS_DATE_SHORT'), false),
                'label' => $note,
                'value' => $ranges === [] ? Text::_('UXS_OPENING_HOURS_CLOSED') : $this->ranges($ranges),
            ];
        }

        return $rows;
    }
}

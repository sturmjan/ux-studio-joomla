<?php

namespace Ux1\UxStudio\Modules\OpeningHours;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Czech statutory public holidays of a year, including the movable Easter dates (anonymous Gregorian algorithm,
 * no ext-calendar dependency).
 */
final class Holidays
{
    /** @return array<string,string> Y-m-d => translated name */
    public static function forYear(int $year): array
    {
        $easter       = strtotime(self::easterSunday($year) . ' 12:00:00 UTC');
        $goodFriday   = gmdate('Y-m-d', strtotime('-2 days', $easter));
        $easterMonday = gmdate('Y-m-d', strtotime('+1 day', $easter));

        return [
            sprintf('%d-01-01', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_NEW_YEAR'),
            $goodFriday                => Text::_('UXS_OPENING_HOURS_HOLIDAY_GOOD_FRIDAY'),
            $easterMonday              => Text::_('UXS_OPENING_HOURS_HOLIDAY_EASTER_MONDAY'),
            sprintf('%d-05-01', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_LABOUR'),
            sprintf('%d-05-08', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_VICTORY'),
            sprintf('%d-07-05', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_CYRIL_METHODIUS'),
            sprintf('%d-07-06', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_JAN_HUS'),
            sprintf('%d-09-28', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_STATEHOOD'),
            sprintf('%d-10-28', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_INDEPENDENCE'),
            sprintf('%d-11-17', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_FREEDOM'),
            sprintf('%d-12-24', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_CHRISTMAS_EVE'),
            sprintf('%d-12-25', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_CHRISTMAS'),
            sprintf('%d-12-26', $year) => Text::_('UXS_OPENING_HOURS_HOLIDAY_ST_STEPHEN'),
        ];
    }

    /** Holiday name of a Y-m-d date, or null when it is not a public holiday. */
    public static function labelFor(string $date): ?string
    {
        return self::forYear((int) substr($date, 0, 4))[$date] ?? null;
    }

    /** Easter Sunday (Gregorian) as Y-m-d. */
    public static function easterSunday(int $year): string
    {
        $a     = $year % 19;
        $b     = intdiv($year, 100);
        $c     = $year % 100;
        $d     = intdiv($b, 4);
        $e     = $b % 4;
        $f     = intdiv($b + 8, 25);
        $g     = intdiv($b - $f + 1, 3);
        $h     = (19 * $a + $b - $d - $g + 15) % 30;
        $i     = intdiv($c, 4);
        $k     = $c % 4;
        $l     = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m     = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

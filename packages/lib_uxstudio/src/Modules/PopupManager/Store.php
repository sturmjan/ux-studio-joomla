<?php

namespace Ux1\UxStudio\Modules\PopupManager;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ApiException;

\defined('_JEXEC') or die;

/**
 * Persistence and validation of popups (#__uxstudio_popups) and their event counters (#__uxstudio_popup_stats).
 * A popup is exposed as one flat array; the columns title/status/priority/starts_at/ends_at are real columns,
 * everything else lives in the JSON column "config".
 */
final class Store
{
    public const TRIGGERS   = ['immediate', 'delay', 'scroll', 'exit'];
    public const POSITIONS  = ['center', 'top', 'bottom', 'bottom-right', 'bottom-left'];
    public const FREQUENCY  = ['always', 'session', 'days', 'once'];
    public const DEVICES    = ['desktop', 'tablet', 'mobile'];
    public const AUDIENCES  = ['all', 'guests', 'members'];
    public const MENU_MODES = ['all', 'include', 'exclude'];
    public const ANIMATIONS = ['fade', 'slide', 'none'];
    public const EVENTS     = ['shown', 'closed', 'clicked'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array<string,mixed> defaults of the config part */
    public static function defaults(): array
    {
        return [
            'headline'        => '',
            'body'            => '',
            'image'           => '',
            'image_alt'       => '',
            'cta_text'        => '',
            'cta_url'         => '',
            'cta_new_tab'     => false,
            'position'        => 'center',
            'animation'       => 'fade',
            'width'           => 520,
            'bg_color'        => '#ffffff',
            'text_color'      => '#1f2937',
            'accent_color'    => '#2563eb',
            'cta_text_color'  => '#ffffff',
            'overlay_opacity' => 60,
            'trigger_type'    => 'delay',
            'delay_seconds'   => 4,
            'scroll_percent'  => 50,
            'exit_delay'      => 3,
            'frequency'       => 'session',
            'frequency_days'  => 7,
            'stop_after_click' => true,
            'menu_mode'       => 'all',
            'menu_items'      => [],
            'url_patterns'    => '',
            'devices'         => [],
            'audience'        => 'all',
        ];
    }

    // ---- reads -----------------------------------------------------------

    /** @return array<int,array<string,mixed>> all popups, newest first, with event counters */
    public function all(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_popups'))->order($db->quoteName('id') . ' DESC'))->loadAssocList() ?: [];
        $stats = $this->stats();
        $out   = [];
        foreach ($rows as $row) {
            $popup          = $this->hydrate($row);
            $popup['stats'] = $stats[(int) $row['id']] ?? ['shown' => 0, 'closed' => 0, 'clicked' => 0];
            $out[]          = $popup;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_popups'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->loadAssoc();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Published popups whose schedule window contains "now", best priority first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function active(): array
    {
        $db     = $this->db;
        $now    = Factory::getDate()->toSql();
        $status = 'publish';
        $query  = $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_popups'))
            ->where($db->quoteName('status') . ' = :s')
            ->where('(' . $db->quoteName('starts_at') . ' IS NULL OR ' . $db->quoteName('starts_at') . ' <= :n1)')
            ->where('(' . $db->quoteName('ends_at') . ' IS NULL OR ' . $db->quoteName('ends_at') . ' >= :n2)')
            ->bind(':s', $status)->bind(':n1', $now)->bind(':n2', $now)
            ->order($db->quoteName('priority') . ' DESC, ' . $db->quoteName('id') . ' ASC');

        return array_map(fn (array $row): array => $this->hydrate($row), $db->setQuery($query, 0, 50)->loadAssocList() ?: []);
    }

    public function countActive(): int
    {
        $db     = $this->db;
        $status = 'publish';

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_popups'))->where($db->quoteName('status') . ' = :s')->bind(':s', $status))->loadResult();
    }

    public function isPublished(int $id): bool
    {
        $db     = $this->db;
        $status = 'publish';

        return (bool) $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__uxstudio_popups'))
                ->where($db->quoteName('id') . ' = :id')->where($db->quoteName('status') . ' = :s')
                ->bind(':id', $id, ParameterType::INTEGER)->bind(':s', $status)
        )->loadResult();
    }

    /** @return array<int,array{shown:int,closed:int,clicked:int}> */
    public function stats(?int $only = null): array
    {
        $db    = $this->db;
        $query = $db->createQuery()->select([$db->quoteName('popup_id'), $db->quoteName('event'), 'COUNT(*) AS ' . $db->quoteName('cnt')])
            ->from($db->quoteName('#__uxstudio_popup_stats'))->group([$db->quoteName('popup_id'), $db->quoteName('event')]);
        if ($only !== null) {
            $query->where($db->quoteName('popup_id') . ' = :p')->bind(':p', $only, ParameterType::INTEGER);
        }
        $out = [];
        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $id = (int) $row['popup_id'];
            $out[$id] ??= ['shown' => 0, 'closed' => 0, 'clicked' => 0];
            if (isset($out[$id][$row['event']])) {
                $out[$id][$row['event']] = (int) $row['cnt'];
            }
        }

        return $out;
    }

    public function recent30(): int
    {
        $db    = $this->db;
        $since = Factory::getDate('-30 days')->toSql();
        $event = 'shown';

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_popup_stats'))
                ->where($db->quoteName('event') . ' = :e')->where($db->quoteName('created') . ' >= :c')->bind(':e', $event)->bind(':c', $since)
        )->loadResult();
    }

    // ---- writes ----------------------------------------------------------

    /**
     * Create (id 0) or update a popup from raw input. Invalid values throw ApiException 422.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $id, array $input): array
    {
        $existing = $id > 0 ? $this->get($id) : null;
        if ($id > 0 && !$existing) {
            throw new ApiException(Text::_('UXS_POPUP_MANAGER_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $base = $existing ?? (self::defaults() + ['title' => '', 'status' => 'draft', 'priority' => 0, 'starts_at' => '', 'ends_at' => '']);
        $in   = $input + $base;

        $title = Support::text($in['title'], 190);
        if ($title === '') {
            throw new ApiException(Text::_('UXS_POPUP_MANAGER_ERR_TITLE'), 422, 'invalid_title');
        }
        $status = (string) $in['status'] === 'publish' ? 'publish' : 'draft';
        $config = $this->sanitizeConfig($in);

        $start = $this->parseWhen($in['starts_at'], 'starts_at');
        $end   = $this->parseWhen($in['ends_at'], 'ends_at');
        if ($start !== null && $end !== null && $end <= $start) {
            throw new ApiException(Text::_('UXS_POPUP_MANAGER_ERR_SCHEDULE'), 422, 'invalid_schedule');
        }
        $priority = Support::intIn($in['priority'], -100, 100, 0);
        $json     = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now      = Factory::getDate()->toSql();
        $db       = $this->db;

        if ($id > 0) {
            $q = $db->createQuery()->update($db->quoteName('#__uxstudio_popups'))
                ->set($db->quoteName('title') . ' = :t')->set($db->quoteName('status') . ' = :s')->set($db->quoteName('priority') . ' = :p')
                ->set($db->quoteName('starts_at') . ' = :a')->set($db->quoteName('ends_at') . ' = :e')->set($db->quoteName('config') . ' = :c')->set($db->quoteName('modified') . ' = :m')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
        } else {
            $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_popups'))
                ->columns($db->quoteName(['title', 'status', 'priority', 'starts_at', 'ends_at', 'config', 'modified', 'created']))
                ->values(':t, :s, :p, :a, :e, :c, :m, :cr')->bind(':cr', $now);
        }
        $q->bind(':t', $title)->bind(':s', $status)->bind(':p', $priority, ParameterType::INTEGER)
            ->bind(':a', $start)->bind(':e', $end)->bind(':c', $json)->bind(':m', $now);
        $db->setQuery($q)->execute();
        if ($id === 0) {
            $id = (int) $db->insertid();
        }

        return $this->get($id) ?? [];
    }

    public function setStatus(int $id, string $status): void
    {
        $db     = $this->db;
        $status = $status === 'publish' ? 'publish' : 'draft';
        $now    = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_popups'))->set($db->quoteName('status') . ' = :s')->set($db->quoteName('modified') . ' = :m')
                ->where($db->quoteName('id') . ' = :id')->bind(':s', $status)->bind(':m', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    public function delete(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_popups'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        $this->resetStats($id);
    }

    public function resetStats(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_popup_stats'))->where($db->quoteName('popup_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    public function track(int $id, string $event): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_popup_stats'))->columns($db->quoteName(['popup_id', 'event', 'created']))->values(':p, :e, :c')
                ->bind(':p', $id, ParameterType::INTEGER)->bind(':e', $event)->bind(':c', $now)
        )->execute();
    }

    // ---- internals -------------------------------------------------------

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $config = json_decode((string) ($row['config'] ?? ''), true);
        $config = \is_array($config) ? $config : [];

        $now   = time();
        $from  = !empty($row['starts_at']) ? (int) strtotime($row['starts_at'] . ' UTC') : 0;
        $until = !empty($row['ends_at']) ? (int) strtotime($row['ends_at'] . ' UTC') : 0;
        $state = (string) $row['status'] !== 'publish' ? 'draft' : ($from > $now ? 'scheduled' : ($until && $until < $now ? 'expired' : 'live'));

        return [
            'id'        => (int) $row['id'],
            'title'     => (string) $row['title'],
            'status'    => (string) $row['status'],
            'state'     => $state,
            'priority'  => (int) $row['priority'],
            'starts_at' => Support::utcToLocal($row['starts_at'] ?? null),
            'ends_at'   => Support::utcToLocal($row['ends_at'] ?? null),
            'created'   => (string) $row['created'],
            'modified'  => (string) $row['modified'],
        ] + array_replace(self::defaults(), array_intersect_key($config, self::defaults()));
    }

    private function parseWhen(mixed $value, string $field): ?string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        $utc = Support::localToUtc($value);
        if ($utc === null) {
            throw new ApiException($field . ': invalid date', 422, 'invalid_date');
        }

        return $utc;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function sanitizeConfig(array $in): array
    {
        $d = self::defaults();
        $url = Support::url($in['cta_url'] ?? '');
        if (trim((string) ($in['cta_url'] ?? '')) !== '' && $url === '') {
            throw new ApiException(Text::_('UXS_POPUP_MANAGER_ERR_URL'), 422, 'invalid_url');
        }
        $enum = static function (mixed $v, array $allowed, string $fallback): string {
            return \is_scalar($v) && \in_array((string) $v, $allowed, true) ? (string) $v : $fallback;
        };

        return [
            'headline'         => Support::text($in['headline'] ?? '', 200),
            'body'             => Support::html($in['body'] ?? ''),
            'image'            => Support::text($in['image'] ?? '', 500),
            'image_alt'        => Support::text($in['image_alt'] ?? '', 200),
            'cta_text'         => Support::text($in['cta_text'] ?? '', 80),
            'cta_url'          => $url,
            'cta_new_tab'      => filter_var($in['cta_new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'position'         => $enum($in['position'] ?? '', self::POSITIONS, $d['position']),
            'animation'        => $enum($in['animation'] ?? '', self::ANIMATIONS, $d['animation']),
            'width'            => Support::intIn($in['width'] ?? null, 240, 1200, $d['width']),
            'bg_color'         => Support::color($in['bg_color'] ?? '', $d['bg_color']),
            'text_color'       => Support::color($in['text_color'] ?? '', $d['text_color']),
            'accent_color'     => Support::color($in['accent_color'] ?? '', $d['accent_color']),
            'cta_text_color'   => Support::color($in['cta_text_color'] ?? '', $d['cta_text_color']),
            'overlay_opacity'  => Support::intIn($in['overlay_opacity'] ?? null, 0, 100, $d['overlay_opacity']),
            'trigger_type'     => $enum($in['trigger_type'] ?? '', self::TRIGGERS, $d['trigger_type']),
            'delay_seconds'    => Support::intIn($in['delay_seconds'] ?? null, 0, 600, $d['delay_seconds']),
            'scroll_percent'   => Support::intIn($in['scroll_percent'] ?? null, 1, 100, $d['scroll_percent']),
            'exit_delay'       => Support::intIn($in['exit_delay'] ?? null, 0, 600, $d['exit_delay']),
            'frequency'        => $enum($in['frequency'] ?? '', self::FREQUENCY, $d['frequency']),
            'frequency_days'   => Support::intIn($in['frequency_days'] ?? null, 1, 365, $d['frequency_days']),
            'stop_after_click' => filter_var($in['stop_after_click'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'menu_mode'        => $enum($in['menu_mode'] ?? '', self::MENU_MODES, $d['menu_mode']),
            'menu_items'       => Support::ids($in['menu_items'] ?? []),
            'url_patterns'     => Support::lines($in['url_patterns'] ?? '', 2000),
            'devices'          => Support::pick($in['devices'] ?? [], self::DEVICES),
            'audience'         => $enum($in['audience'] ?? '', self::AUDIENCES, $d['audience']),
        ];
    }
}

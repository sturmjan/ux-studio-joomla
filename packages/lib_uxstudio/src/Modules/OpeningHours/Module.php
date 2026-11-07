<?php

namespace Ux1\UxStudio\Modules\OpeningHours;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Opening Hours: locations with weekly hours, exceptions (incl. Czech public holidays), dated announcements and a quick
 * "closed for N minutes" override; an "open now" resolver (admin + public status API), a front-end renderer
 * (Joomla module mod_uxstudio_opening_hours, {opening-hours id=N} / {opening-hours-status id=N} in content) and
 * schema.org LocalBusiness + OpeningHoursSpecification JSON-LD.
 */
final class Module extends BaseModule
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private ?Renderer $renderer = null;

    public function boot(ModuleContext $ctx): void
    {
        if ($ctx->isSite()) {
            $ctx->on('onContentPrepare', [$this, 'contentPrepare']);
            $ctx->on('onBeforeCompileHead', [$this, 'jsonLd']);
        }
    }

    public function renderer(): Renderer
    {
        return $this->renderer ??= new Renderer($this, $this->ctx());
    }

    /* ---------------------------------------------------------------- settings */

    public function settingsSchema(): array
    {
        $p = 'UXS_OPENING_HOURS_';

        return [
            ['key' => 'holidays_closed', 'type' => 'toggle', 'section' => $p . 'SECTION_GENERAL', 'label' => $p . 'HOLIDAYS_CLOSED', 'help' => $p . 'HOLIDAYS_CLOSED_HELP', 'default' => true],
            ['key' => 'show_location_dropdown', 'type' => 'toggle', 'section' => $p . 'SECTION_GENERAL', 'label' => $p . 'DROPDOWN', 'help' => $p . 'DROPDOWN_HELP', 'default' => false],
            ['key' => 'severity_info_color', 'type' => 'color', 'section' => $p . 'SECTION_GENERAL', 'label' => $p . 'COLOR_INFO', 'default' => '#2271b1'],
            ['key' => 'severity_warning_color', 'type' => 'color', 'section' => $p . 'SECTION_GENERAL', 'label' => $p . 'COLOR_WARNING', 'default' => '#8a5a00'],
            ['key' => 'severity_critical_color', 'type' => 'color', 'section' => $p . 'SECTION_GENERAL', 'label' => $p . 'COLOR_CRITICAL', 'default' => '#d63638'],
            ['key' => 'schema_enabled', 'type' => 'toggle', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_ENABLED', 'help' => $p . 'SCHEMA_ENABLED_HELP', 'default' => false],
            ['key' => 'schema_on_homepage', 'type' => 'toggle', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_HOME', 'default' => true, 'show_if' => ['key' => 'schema_enabled', 'truthy' => true]],
            ['key' => 'schema_pages', 'type' => 'text', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_PAGES', 'help' => $p . 'SCHEMA_PAGES_HELP', 'default' => '', 'maxlength' => 200, 'show_if' => ['key' => 'schema_enabled', 'truthy' => true]],
            ['key' => 'schema_location_ids', 'type' => 'text', 'section' => $p . 'SECTION_SEO', 'label' => $p . 'SCHEMA_LOCATIONS', 'help' => $p . 'SCHEMA_LOCATIONS_HELP', 'default' => '', 'maxlength' => 200, 'show_if' => ['key' => 'schema_enabled', 'truthy' => true]],
            ['key' => 'google_maps_api_key', 'type' => 'password', 'section' => $p . 'SECTION_GEOCODING', 'label' => $p . 'GOOGLE_KEY', 'help' => $p . 'GOOGLE_KEY_HELP'],
            ['key' => 'mapy_cz_api_key', 'type' => 'password', 'section' => $p . 'SECTION_GEOCODING', 'label' => $p . 'MAPY_KEY', 'help' => $p . 'MAPY_KEY_HELP'],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_oh_locations` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(190) NOT NULL DEFAULT \'\', `address` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`phone` VARCHAR(60) NOT NULL DEFAULT \'\', `lat` DECIMAL(10,7) NULL, `lng` DECIMAL(10,7) NULL, '
                . '`hours` MEDIUMTEXT NULL, `exceptions` MEDIUMTEXT NULL, `announcements` MEDIUMTEXT NULL, '
                . '`override_until` INT UNSIGNED NOT NULL DEFAULT 0, `override_reason` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`ordering` INT NOT NULL DEFAULT 0, `created` DATETIME NOT NULL, `modified` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $this->ctx()->app()->get('offset', 'UTC'));
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    public function holidaysClosed(): bool
    {
        return (bool) $this->ctx()->setting('holidays_closed', true);
    }

    /* ---------------------------------------------------------------- data */

    /** @return array<int,array<string,mixed>> */
    public function listLocations(): array
    {
        $db = $this->ctx()->db();
        try {
            $list = $db->setQuery(
                $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_oh_locations'))
                    ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('title') . ' ASC, ' . $db->quoteName('id') . ' ASC')
            )->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        return array_map([$this, 'shape'], $list);
    }

    public function getLocation(int $id): ?array
    {
        $db  = $this->ctx()->db();
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_oh_locations'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return \is_array($row) ? $this->shape($row) : null;
    }

    /** @param array<string,mixed> $r */
    private function shape(array $r): array
    {
        $json  = static function (mixed $v): array {
            $d = json_decode((string) $v, true);

            return \is_array($d) ? $d : [];
        };
        $until = (int) $r['override_until'];

        return [
            'id'            => (int) $r['id'],
            'title'         => (string) $r['title'],
            'address'       => (string) $r['address'],
            'phone'         => (string) $r['phone'],
            'lat'           => $r['lat'] === null ? null : (float) $r['lat'],
            'lng'           => $r['lng'] === null ? null : (float) $r['lng'],
            'hours'         => $json($r['hours']),
            'exceptions'    => $json($r['exceptions']),
            'announcements' => $json($r['announcements']),
            'override'      => $until > time() ? ['until' => $until, 'reason' => (string) $r['override_reason'], 'kind' => 'closed'] : null,
        ];
    }

    /* ---------------------------------------------------------------- sanitizing */

    public static function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param mixed $ranges list of {open, close}
     * @return array<int,array{open:string,close:string}>
     */
    private static function cleanRanges(mixed $ranges): array
    {
        $out = [];
        foreach (\is_array($ranges) ? $ranges : [] as $range) {
            if (!\is_array($range)) {
                continue;
            }
            $open  = (string) ($range['open'] ?? '');
            $close = (string) ($range['close'] ?? '');
            if (self::isValidTime($open) && self::isValidTime($close)) {
                $out[] = ['open' => $open, 'close' => $close];
            }
        }

        return \array_slice($out, 0, 6);
    }

    /** @return array<string,array<int,array{open:string,close:string}>> */
    public static function sanitizeHours(mixed $hours): array
    {
        $hours = \is_array($hours) ? $hours : [];
        $out   = [];
        foreach (self::DAYS as $day) {
            $out[$day] = self::cleanRanges($hours[$day] ?? []);
        }

        return $out;
    }

    /** @return array<int,array{date:string,closed:bool,label:string,hours:array}> */
    public static function sanitizeExceptions(mixed $items): array
    {
        $out = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item) || !self::isValidDate((string) ($item['date'] ?? ''))) {
                continue;
            }
            $out[] = [
                'date'   => (string) $item['date'],
                'closed' => filter_var($item['closed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'label'  => mb_substr(trim(strip_tags((string) ($item['label'] ?? ''))), 0, 120),
                'hours'  => self::cleanRanges($item['hours'] ?? []),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return \array_slice($out, 0, 400);
    }

    /** @return array<int,array{text:string,from:string,to:string,severity:string}> */
    public static function sanitizeAnnouncements(mixed $items): array
    {
        $out = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $text = mb_substr(trim(strip_tags((string) ($item['text'] ?? ''))), 0, 300);
            if ($text === '') {
                continue;
            }
            $from = (string) ($item['from'] ?? '');
            $to   = (string) ($item['to'] ?? '');
            $sev  = (string) ($item['severity'] ?? 'info');
            $out[] = [
                'text'     => $text,
                'from'     => self::isValidDate($from) ? $from : '',
                'to'       => self::isValidDate($to) ? $to : '',
                'severity' => \in_array($sev, ['info', 'warning', 'critical'], true) ? $sev : 'info',
            ];
        }

        return \array_slice($out, 0, 20);
    }

    /** Most severe announcement valid on a Y-m-d date, or null. */
    public static function activeAnnouncement(array $items, string $date): ?array
    {
        $best  = null;
        $score = -1;
        $rank  = ['info' => 0, 'warning' => 1, 'critical' => 2];
        foreach ($items as $a) {
            if (!\is_array($a) || ($a['text'] ?? '') === '') {
                continue;
            }
            if (($a['from'] ?? '') !== '' && $date < $a['from']) {
                continue;
            }
            if (($a['to'] ?? '') !== '' && $date > $a['to']) {
                continue;
            }
            $s = $rank[$a['severity'] ?? 'info'] ?? 0;
            if ($s > $score) {
                $best  = $a;
                $score = $s;
            }
        }

        return $best;
    }

    /* ---------------------------------------------------------------- open now resolver */

    /**
     * Effective {open, close} ranges of a location on a date: exception > public holiday > weekly schedule.
     * The temporary override is not applied here (it only affects "right now").
     *
     * @return array<int,array{open:string,close:string}>
     */
    public function dayRanges(array $location, string $date): array
    {
        foreach ((array) ($location['exceptions'] ?? []) as $ex) {
            if (($ex['date'] ?? '') === $date) {
                return !empty($ex['closed']) ? [] : self::cleanRanges($ex['hours'] ?? []);
            }
        }
        if ($this->holidaysClosed() && Holidays::labelFor($date) !== null) {
            return [];
        }
        $index = (int) (new \DateTimeImmutable($date, $this->timezone()))->format('N') - 1;
        $day   = self::DAYS[$index] ?? 'mon';

        return self::cleanRanges($location['hours'][$day] ?? []);
    }

    /** Exception label or public holiday name of a date, '' when none. */
    public function dayNote(array $location, string $date): string
    {
        foreach ((array) ($location['exceptions'] ?? []) as $ex) {
            if (($ex['date'] ?? '') === $date && ($ex['label'] ?? '') !== '') {
                return (string) $ex['label'];
            }
        }
        if ($this->holidaysClosed()) {
            return Holidays::labelFor($date) ?? '';
        }

        return '';
    }

    /** @return array{start:int,end:int}|null a range anchored on $date; close <= open rolls into the next day */
    private function interval(string $date, array $range): ?array
    {
        try {
            $tz    = $this->timezone();
            $start = new \DateTimeImmutable($date . ' ' . $range['open'] . ':00', $tz);
            $end   = new \DateTimeImmutable($date . ' ' . $range['close'] . ':00', $tz);
        } catch (\Throwable $e) {
            return null;
        }
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
    }

    /**
     * Whether a location is open right now (site time zone). Yesterday's overnight tail counts, a manual override beats all.
     *
     * @return array{open:bool,location_id:int,checked_at:string,next_change:?string,override:?array}
     */
    public function status(array $location): array
    {
        $tz        = $this->timezone();
        $now       = new \DateTimeImmutable('now', $tz);
        $today     = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $nowTs     = $now->getTimestamp();

        $intervals = [];
        foreach ([$yesterday, $today] as $date) {
            foreach ($this->dayRanges($location, $date) as $range) {
                if (($iv = $this->interval($date, $range)) !== null) {
                    $intervals[] = $iv;
                }
            }
        }
        usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $open = false;
        $next = null;
        foreach ($intervals as $iv) {
            if ($nowTs >= $iv['start'] && $nowTs < $iv['end']) {
                $open = true;
                $next = $iv['end'];
                break;
            }
        }
        if (!$open) {
            foreach ($intervals as $iv) {
                if ($iv['start'] > $nowTs) {
                    $next = $iv['start'];
                    break;
                }
            }
        }
        $override = $location['override'] ?? null;
        if (\is_array($override)) {
            $open = false;
            $next = (int) $override['until'];
        }

        return [
            'open'        => $open,
            'location_id' => (int) $location['id'],
            'checked_at'  => $now->format(DATE_ATOM),
            'next_change' => $next === null ? null : (new \DateTimeImmutable('@' . $next))->setTimezone($tz)->format(DATE_ATOM),
            'override'    => \is_array($override) ? ['until' => (int) $override['until'], 'reason' => (string) $override['reason']] : null,
        ];
    }

    /** Timestamp of the next opening within 14 days (never before an override ends), or null. */
    public function nextOpen(array $location): ?int
    {
        $now   = new \DateTimeImmutable('now', $this->timezone());
        $nowTs = $now->getTimestamp();
        $floor = $nowTs;
        if (\is_array($location['override'] ?? null)) {
            $floor = max($floor, (int) $location['override']['until']);
        }
        $best = null;
        for ($i = -1; $i < 14; $i++) {
            $date = $now->modify(($i >= 0 ? '+' : '') . $i . ' day')->format('Y-m-d');
            foreach ($this->dayRanges($location, $date) as $range) {
                $iv = $this->interval($date, $range);
                if ($iv === null || $iv['end'] <= $floor) {
                    continue;
                }
                $candidate = max($iv['start'], $floor);
                if ($candidate > $nowTs && ($best === null || $candidate < $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /* ---------------------------------------------------------------- front-end hooks */

    /** onContentPrepare: {opening-hours id=1}, {opening-hours id=all}, {opening-hours-status id=1}. */
    public function contentPrepare(Event $event): void
    {
        $item = $event->hasArgument('subject') ? $event->getArgument('subject') : $event->getArgument(1);
        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !str_contains($item->text, '{opening-hours')) {
            return;
        }
        $item->text = (string) preg_replace_callback(
            '/\{opening-hours(-status)?([^}]*)\}/i',
            function (array $m): string {
                $o = [];
                if (preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $m[2], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $o[strtolower($p[1])] = $p[4] ?? ($p[3] ?? $p[2]);
                    }
                }
                $id = strtolower(trim((string) ($o['id'] ?? $o['location'] ?? '0')));
                try {
                    return $this->renderer()->front($id === 'all' ? 'all' : (int) $id, $m[1] !== '' ? 'status' : 'card');
                } catch (\Throwable $e) {
                    return '';
                }
            },
            $item->text
        );
    }

    /** onBeforeCompileHead: LocalBusiness JSON-LD on the home page and the configured menu items. */
    public function jsonLd(Event $event): void
    {
        $ctx = $this->ctx();
        $app = $ctx->app();
        if (!$ctx->setting('schema_enabled', false) || $app->getDocument()?->getType() !== 'html' || $app->getInput()->getCmd('tmpl', '') === 'component') {
            return;
        }
        $menu    = $app->getMenu();
        $active  = $menu->getActive();
        $default = $menu->getDefault($app->getLanguage()->getTag());
        $isHome  = $active && $default && (int) $active->id === (int) $default->id;
        $pages   = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $ctx->setting('schema_pages', '')) ?: []));
        $onPage  = $active && $pages !== [] && \in_array((int) $active->id, $pages, true);
        if (!(($isHome && $ctx->setting('schema_on_homepage', true)) || $onPage)) {
            return;
        }

        $wanted = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $ctx->setting('schema_location_ids', '')) ?: []));
        $items  = [];
        foreach ($this->listLocations() as $location) {
            if ($wanted === [] || \in_array($location['id'], $wanted, true)) {
                $items[] = $this->schemaFor($location);
            }
        }
        if ($items === []) {
            return;
        }
        $payload = \count($items) === 1 ? $items[0] : ['@context' => 'https://schema.org', '@graph' => array_map(static function (array $i): array {
            unset($i['@context']);

            return $i;
        }, $items)];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($json !== false) {
            $app->getDocument()->getWebAssetManager()->addInlineScript($json, ['name' => 'uxs.opening-hours.schema'], ['type' => 'application/ld+json']);
        }
    }

    /** schema.org LocalBusiness with OpeningHoursSpecification (weekly hours + dated exceptions and holidays of the next year). */
    public function schemaFor(array $location): array
    {
        $names = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
        $data  = [
            '@context' => 'https://schema.org',
            '@type'    => 'LocalBusiness',
            '@id'      => rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/#uxstudio-oh-' . $location['id'],
            'name'     => $location['title'],
        ];
        if ($location['address'] !== '') {
            $data['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $location['address']];
        }
        if ($location['phone'] !== '') {
            $data['telephone'] = $location['phone'];
        }
        if ($location['lat'] !== null && $location['lng'] !== null) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $location['lat'], 'longitude' => $location['lng']];
        }

        $spec = [];
        foreach ($names as $key => $name) {
            foreach (self::cleanRanges($location['hours'][$key] ?? []) as $r) {
                $spec[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $name, 'opens' => $r['open'], 'closes' => $r['close']];
            }
        }
        // Dated exceptions and (when treated as closed) public holidays of the next 12 months.
        $tz    = $this->timezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $limit = (new \DateTimeImmutable('now', $tz))->modify('+1 year')->format('Y-m-d');
        $dated = [];
        foreach ((array) $location['exceptions'] as $ex) {
            if ($ex['date'] >= $today && $ex['date'] <= $limit) {
                $dated[$ex['date']] = !empty($ex['closed']) ? [] : self::cleanRanges($ex['hours'] ?? []);
            }
        }
        if ($this->holidaysClosed()) {
            foreach ([(int) substr($today, 0, 4), (int) substr($limit, 0, 4)] as $year) {
                foreach (Holidays::forYear($year) as $date => $label) {
                    if ($date >= $today && $date <= $limit && !isset($dated[$date])) {
                        $dated[$date] = [];
                    }
                }
            }
        }
        ksort($dated);
        foreach ($dated as $date => $ranges) {
            if ($ranges === []) {
                // schema.org convention for a closed day: opens and closes at the same time.
                $spec[] = ['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => $date, 'validThrough' => $date];
                continue;
            }
            foreach ($ranges as $r) {
                $spec[] = ['@type' => 'OpeningHoursSpecification', 'opens' => $r['open'], 'closes' => $r['close'], 'validFrom' => $date, 'validThrough' => $date];
            }
        }
        if ($spec !== []) {
            $data['openingHoursSpecification'] = $spec;
        }

        return $data;
    }

    /** Called by the Joomla module (mod_uxstudio_opening_hours). */
    public function renderFront(array $options): string
    {
        $loc = $options['location'] ?? 0;

        return $this->renderer()->front($loc === 'all' ? 'all' : (int) $loc, ($options['display'] ?? 'card') === 'status' ? 'status' : 'card', (string) ($options['css_class'] ?? ''));
    }

    /* ---------------------------------------------------------------- API */

    public function routes(): array
    {
        return [
            'GET locations'                       => [$this, 'apiList'],
            'POST locations'                      => [$this, 'apiCreate'],
            'POST locations/{id}'                 => [$this, 'apiUpdate'],
            'POST locations/{id}/delete'          => ['handler' => [$this, 'apiDelete'], 'acl' => 'core.admin'],
            'POST locations/{id}/override'        => [$this, 'apiOverride'],
            'POST locations/{id}/override/clear'  => [$this, 'apiOverrideClear'],
            'GET locations/{id}/status'           => [$this, 'apiStatus'],
            'GET geocode'                         => [$this, 'apiGeocode'],
        ];
    }

    /** Live status of a location, no login: read-only and without secrets (for widgets of the site). */
    public function publicRoutes(): array
    {
        return [
            'GET locations/{id}/status' => ['handler' => [$this, 'apiStatus'], 'auth' => 'none', 'limit' => [60, 60]],
        ];
    }

    public function apiList(ApiRequest $r): array
    {
        $items = array_map(fn (array $l): array => $l + ['status' => $this->status($l)], $this->listLocations());

        return ['items' => $items, 'holidays' => Holidays::forYear((int) (new \DateTimeImmutable('now', $this->timezone()))->format('Y'))];
    }

    private function mustFind(int $id): array
    {
        $location = $this->getLocation($id);
        if ($location === null) {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $location;
    }

    public function apiStatus(ApiRequest $r): array
    {
        return $this->status($this->mustFind((int) $r->param('id')));
    }

    public function apiCreate(ApiRequest $r): array
    {
        $v   = $this->validate($r->body(), null);
        $db  = $this->ctx()->db();
        $now = Factory::getDate()->toSql();
        $max = (int) $db->setQuery($db->createQuery()->select('MAX(' . $db->quoteName('ordering') . ')')->from($db->quoteName('#__uxstudio_oh_locations')))->loadResult();
        $ord = $max + 1;
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_oh_locations'))
                ->columns($db->quoteName(['title', 'address', 'phone', 'lat', 'lng', 'hours', 'exceptions', 'announcements', 'ordering', 'created', 'modified']))
                ->values(':title, :addr, :phone, :lat, :lng, :hours, :exc, :ann, :ord, :cr, :mo')
                ->bind(':title', $v['title'])->bind(':addr', $v['address'])->bind(':phone', $v['phone'])
                ->bind(':lat', $v['lat'], $v['lat'] === null ? ParameterType::NULL : ParameterType::STRING)->bind(':lng', $v['lng'], $v['lng'] === null ? ParameterType::NULL : ParameterType::STRING)
                ->bind(':hours', $v['hours'])->bind(':exc', $v['exceptions'])->bind(':ann', $v['announcements'])
                ->bind(':ord', $ord, ParameterType::INTEGER)->bind(':cr', $now)->bind(':mo', $now)
        )->execute();

        return $this->mustFind((int) $db->insertid());
    }

    public function apiUpdate(ApiRequest $r): array
    {
        $id  = (int) $r->param('id');
        $old = $this->mustFind($id);
        $v   = $this->validate($r->body(), $old);
        $db  = $this->ctx()->db();
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_oh_locations'))
                ->set($db->quoteName('title') . ' = :title')->set($db->quoteName('address') . ' = :addr')->set($db->quoteName('phone') . ' = :phone')
                ->set($db->quoteName('lat') . ' = :lat')->set($db->quoteName('lng') . ' = :lng')->set($db->quoteName('hours') . ' = :hours')
                ->set($db->quoteName('exceptions') . ' = :exc')->set($db->quoteName('announcements') . ' = :ann')->set($db->quoteName('modified') . ' = :mo')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':title', $v['title'])->bind(':addr', $v['address'])->bind(':phone', $v['phone'])
                ->bind(':lat', $v['lat'], $v['lat'] === null ? ParameterType::NULL : ParameterType::STRING)->bind(':lng', $v['lng'], $v['lng'] === null ? ParameterType::NULL : ParameterType::STRING)
                ->bind(':hours', $v['hours'])->bind(':exc', $v['exceptions'])->bind(':ann', $v['announcements'])->bind(':mo', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $this->mustFind($id);
    }

    public function apiDelete(ApiRequest $r): array
    {
        $id = (int) $this->mustFind((int) $r->param('id'))['id'];
        $db = $this->ctx()->db();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_oh_locations'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return ['deleted' => true];
    }

    /** POST locations/{id}/override {minutes: 1..10080, reason} - close for N minutes. */
    public function apiOverride(ApiRequest $r): array
    {
        $id      = (int) $this->mustFind((int) $r->param('id'))['id'];
        $minutes = $r->int('minutes');
        if ($minutes < 1 || $minutes > 10080) {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_MINUTES'), 422, 'invalid_value');
        }

        return $this->writeOverride($id, time() + $minutes * 60, mb_substr(trim(strip_tags($r->string('reason'))), 0, 255));
    }

    public function apiOverrideClear(ApiRequest $r): array
    {
        return $this->writeOverride((int) $this->mustFind((int) $r->param('id'))['id'], 0, '');
    }

    private function writeOverride(int $id, int $until, string $reason): array
    {
        $db = $this->ctx()->db();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_oh_locations'))
                ->set($db->quoteName('override_until') . ' = :u')->set($db->quoteName('override_reason') . ' = :r')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':u', $until, ParameterType::INTEGER)->bind(':r', $reason)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $this->mustFind($id);
    }

    /**
     * @param array<string,mixed>      $in  request body
     * @param array<string,mixed>|null $old current location (missing keys keep their value)
     * @return array<string,mixed> values ready for the database
     */
    private function validate(array $in, ?array $old): array
    {
        $get = static fn (string $k, mixed $d) => \array_key_exists($k, $in) ? $in[$k] : ($old[$k] ?? $d);
        $str = static fn (mixed $v, int $max): string => mb_substr(trim(strip_tags((string) (\is_scalar($v) ? $v : ''))), 0, $max);

        $title = $str($get('title', ''), 190);
        if ($title === '') {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_TITLE'), 422, 'invalid_value');
        }
        $coord = static function (mixed $v, float $limit): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (!is_numeric($v) || abs((float) $v) > $limit) {
                throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_COORD'), 422, 'invalid_value');
            }

            return number_format((float) $v, 7, '.', '');
        };
        $enc = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'title'         => $title,
            'address'       => $str($get('address', ''), 255),
            'phone'         => $str($get('phone', ''), 60),
            'lat'           => $coord($get('lat', null), 90.0),
            'lng'           => $coord($get('lng', null), 180.0),
            'hours'         => $enc(self::sanitizeHours($get('hours', []))),
            'exceptions'    => $enc(self::sanitizeExceptions($get('exceptions', []))),
            'announcements' => $enc(self::sanitizeAnnouncements($get('announcements', []))),
        ];
    }

    /* ---------------------------------------------------------------- geocoding */

    /** GET geocode?address= - Google Maps when its key is set, Mapy.cz otherwise. Keys never leave the server. */
    public function apiGeocode(ApiRequest $r): array
    {
        $address = trim($r->string('address'));
        if ($address === '') {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_ADDRESS'), 422, 'invalid_value');
        }
        $ctx    = $this->ctx();
        $google = (string) $ctx->setting('google_maps_api_key', '');
        $mapy   = (string) $ctx->setting('mapy_cz_api_key', '');
        if ($google === '' && $mapy === '') {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_NO_GEOCODER'), 424, 'no_geocoder');
        }

        try {
            if ($google !== '') {
                $res  = $ctx->http()->get('https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $address, 'key' => $google]), ['timeout' => 10]);
                $body = json_decode($res['body'], true);
                $hit  = \is_array($body) && ($body['status'] ?? '') === 'OK' ? ($body['results'][0] ?? null) : null;
                if (!\is_array($hit)) {
                    throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_GEOCODE'), 502, 'geocode_failed');
                }

                return [
                    'lat'               => (float) ($hit['geometry']['location']['lat'] ?? 0),
                    'lng'               => (float) ($hit['geometry']['location']['lng'] ?? 0),
                    'formatted_address' => (string) ($hit['formatted_address'] ?? $address),
                ];
            }
            $res  = $ctx->http()->get('https://api.mapy.cz/v1/geocode?' . http_build_query(['query' => $address, 'lang' => 'cs', 'limit' => 1, 'apikey' => $mapy]), ['timeout' => 10]);
            $body = json_decode($res['body'], true);
            $hit  = \is_array($body) ? ($body['items'][0] ?? null) : null;
            if (!\is_array($hit) || !isset($hit['position']['lat'], $hit['position']['lon'])) {
                throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_GEOCODE'), 502, 'geocode_failed');
            }

            return ['lat' => (float) $hit['position']['lat'], 'lng' => (float) $hit['position']['lon'], 'formatted_address' => (string) ($hit['name'] ?? $address)];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiException(Text::_('UXS_OPENING_HOURS_ERR_GEOCODE'), 502, 'geocode_failed');
        }
    }

    public function dashboardWidget(): ?array
    {
        $locations = $this->listLocations();
        $open      = 0;
        foreach ($locations as $l) {
            $open += $this->status($l)['open'] ? 1 : 0;
        }

        return [
            'title'  => Text::_('UXS_OPENING_HOURS_WIDGET_TITLE'),
            'value'  => $locations === [] ? '0' : $open . ' / ' . \count($locations),
            'text'   => Text::_('UXS_OPENING_HOURS_WIDGET_TEXT'),
            'status' => $locations === [] ? 'warn' : 'ok',
        ];
    }
}

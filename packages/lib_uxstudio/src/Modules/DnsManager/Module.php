<?php

namespace Ux1\UxStudio\Modules\DnsManager;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserHelper;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;
use Ux1\UxStudio\Modules\CaConnector\CaClient;

\defined('_JEXEC') or die;

/**
 * DNS management through UX1 Network app's broker (`?page=dns_manage`). The Cloudflare token lives only in UX1 Network app,
 * which also derives the zone from the signed site_url, validates every change, keeps the authoritative log and e-mails
 * the administrators. This module is the site side: who may edit (core.admin + an explicit list), the safety net around
 * critical records (MX, the website's own records), a fresh password confirmation, the local audit log and a propagation check.
 *
 * Without a paired UX1 Network app the module still opens and explains the state (state = connector_off | not_paired | ...).
 */
final class Module extends BaseModule
{
    /** A write needs a password confirmation younger than this (UX1 Network app checks the same limit). */
    private const REAUTH_SECONDS = 900;

    private const SESSION_REAUTH = 'uxs.dns.reauth';

    /** @var array<string,mixed>|null */
    private ?array $connection = null;

    /** allow_critical_delete, read once per request. */
    private ?bool $allowCritical = null;

    public function boot(ModuleContext $ctx): void
    {
        // Nothing to hook: the module is API only.
    }

    /**
     * Deliberately empty: the safety switches (who may edit, allow_critical_delete) must not live in the generic module
     * settings, which any core.manage user (Administrator group) may save. They are kept in the module Store and written
     * only by `POST config`, a core.admin route that also asks for the password.
     */
    public function settingsSchema(): array
    {
        return [];
    }

    public function routes(): array
    {
        $admin = static fn (callable $h): array => ['handler' => $h, 'acl' => 'core.admin'];

        return [
            'GET status'                 => [$this, 'status'],
            'GET config'                 => $admin([$this, 'getConfig']),
            'POST config'                => $admin([$this, 'saveConfig']),
            'GET records'                => [$this, 'records'],
            'GET log'                    => [$this, 'log'],
            'GET propagation'            => [$this, 'propagation'],
            'POST ack'                   => $admin([$this, 'ack']),
            'POST reauth'                => $admin([$this, 'reauth']),
            'POST records'               => $admin([$this, 'create']),
            'POST records/{id}'          => $admin([$this, 'update']),
            'POST records/{id}/delete'   => $admin([$this, 'delete']),
            'POST revert'                => $admin([$this, 'revert']),
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_dns_log` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `batch_id` VARCHAR(32) NOT NULL DEFAULT \'\', '
                . '`user_id` INT UNSIGNED NOT NULL DEFAULT 0, `user_login` VARCHAR(150) NOT NULL DEFAULT \'\', `user_name` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . '`action` VARCHAR(16) NOT NULL DEFAULT \'\', `status` VARCHAR(10) NOT NULL DEFAULT \'success\', '
                . '`record_id` VARCHAR(64) NOT NULL DEFAULT \'\', `record_type` VARCHAR(10) NOT NULL DEFAULT \'\', `record_name` VARCHAR(253) NOT NULL DEFAULT \'\', '
                . '`before_json` MEDIUMTEXT NULL, `after_json` MEDIUMTEXT NULL, `error` VARCHAR(500) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_user_action` (`user_id`, `action`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_dns_log', 'column' => 'created', 'days' => 365]];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $n = $this->journal()->changesSince(30);
        } catch (\Throwable $e) {
            return null;
        }

        return ['title' => Text::_('UXS_DNS_MANAGER_NAME'), 'value' => $n, 'text' => Text::_('UXS_DNS_MANAGER_WIDGET'), 'status' => 'ok'];
    }

    // ---------------------------------------------------------------- read routes

    /** GET dns-manager/status - connection state and the rights of the current user. */
    public function status(ApiRequest $r): array
    {
        $conn = $this->connection();
        $user = $r->user();
        $left = $this->reauthLeft();

        return [
            'state'          => $conn['state'],
            'reason'         => $conn['reason'],
            'message'        => $conn['message'],
            'zone'           => $conn['zone'],
            'site_host'      => $this->siteHost(),
            'is_admin'       => (bool) $user->authorise('core.admin'),
            'can_manage'     => $this->canManage($r),
            'acknowledged'   => $this->journal()->acknowledged((int) $user->id),
            'reauth_left'    => $left,
            'allow_critical' => $this->allowCritical(),
        ];
    }

    /** GET dns-manager/records[?fresh=1] */
    public function records(ApiRequest $r): array
    {
        $conn = $this->connection();
        if ($conn['state'] !== 'active') {
            return ['active' => false, 'state' => $conn['state'], 'records' => [], 'zone' => $conn['zone']];
        }
        $res  = $this->broker('GET', 'list', $r->bool('fresh') ? ['fresh' => 1] : []);
        $zone = (string) ($res['zone'] ?? $conn['zone']);
        $host = $this->siteHost();
        $allow = $this->allowCritical();

        $records = [];
        foreach ((array) ($res['records'] ?? []) as $rec) {
            if (!\is_array($rec)) {
                continue;
            }
            $risk                  = Guard::risks($rec, $zone, $host);
            $rec['risk']           = $risk;
            $rec['critical']       = Guard::isCritical($risk);
            $rec['delete_blocked'] = $rec['critical'] && !$allow;
            $records[]             = $rec;
        }

        return ['active' => true, 'zone' => $zone, 'records' => $records, 'cached' => !empty($res['cached']), 'allow_critical' => $allow];
    }

    /** GET dns-manager/log - history from UX1 Network app (authoritative), the local copy when it is not reachable. */
    public function log(ApiRequest $r): array
    {
        $conn = $this->connection();
        if ($conn['state'] === 'active' || $conn['state'] === 'inactive') {
            $query = [];
            foreach (['user_id', 'log_action', 'log_status', 'from', 'to', 'page_no', 'per_page'] as $key) {
                $v = $r->query($key);
                if (\is_scalar($v) && (string) $v !== '' && preg_match('/^[\w\- :.]{1,40}$/', (string) $v) === 1) {
                    $query[$key] = (string) $v;
                }
            }
            try {
                $res = $this->broker('GET', 'log', $query);
                unset($res['success']);
                $res['source'] = 'central';
                $zone          = $this->zone();
                $host          = $this->siteHost();
                $res['rows']   = array_map(function ($row) use ($zone, $host): mixed {
                    if (!\is_array($row)) {
                        return $row;
                    }
                    $plan = $this->revertPlan($row, $zone, $host);

                    return $plan === null ? $row : $row + ['revert_risk' => $plan['risks'], 'revert_name' => $plan['name'], 'revert_blocked' => $plan['blocked']];
                }, (array) ($res['rows'] ?? []));

                return $res;
            } catch (ApiException $e) {
                if ($e->status() < 500 && $e->status() !== 424) {
                    throw $e;
                }
            }
        }
        $rows = $this->journal()->recent(50);

        return ['rows' => $rows, 'total' => \count($rows), 'users' => [], 'source' => 'local'];
    }

    /** GET dns-manager/propagation?type=&name=&content=&priority=&proxied=&absent= */
    public function propagation(ApiRequest $r): array
    {
        $conn = $this->connection();
        if ($conn['state'] !== 'active' || $conn['zone'] === '') {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NOT_ACTIVE'), 409, 'not_active');
        }
        $zone = (string) $conn['zone'];
        $type = strtoupper($r->string('type'));
        if (!\in_array($type, Guard::WRITABLE, true)) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_TYPE'), 422, 'invalid_type');
        }
        $fqdn = Guard::fqdn($r->string('name'), $zone);
        if (\strlen($fqdn) > 253 || ($fqdn !== $zone && !str_ends_with($fqdn, '.' . $zone))
            || preg_match('/^[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?$/', $fqdn) !== 1) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NAME'), 422, 'invalid_name');
        }
        $proxied  = $r->bool('proxied');
        $expected = null;
        if ($r->bool('absent')) {
            $expected = '';
        } elseif (!$proxied && $r->string('content') !== '') {
            $content  = $r->string('content');
            $expected = Propagation::normalize($type, $type === 'MX' ? ((int) $r->int('priority')) . ' ' . $content : $content);
        }
        $res            = (new Propagation($this->ctx()->http()))->check($fqdn, $type, $expected);
        $res['proxied'] = $proxied;

        return $res;
    }

    // ---------------------------------------------------------------- write routes

    /** POST dns-manager/reauth {password} - confirms the password of the logged-in user for 15 minutes. */
    public function reauth(ApiRequest $r): array
    {
        $this->verifyPassword($r);
        $this->ctx()->app()->getSession()->set(self::SESSION_REAUTH, time());

        return ['reauth_left' => self::REAUTH_SECONDS];
    }

    /** POST dns-manager/ack - the user confirms that DNS changes are risky (needed once per user). */
    public function ack(ApiRequest $r): array
    {
        $this->requireManager($r);
        $this->broker('POST', 'ack', [], ['actor' => $this->actor($r)]);
        $this->journal()->add($r->user(), 'acknowledged', 'success', null, null);

        return ['acknowledged' => true];
    }

    /** POST dns-manager/records {record, confirm} */
    public function create(ApiRequest $r): array
    {
        $this->guardWrite($r);
        $record = $this->input($r);
        $after  = array_merge($record, ['name' => Guard::fqdn($record['name'], $this->zone())]);

        return $this->send($r, 'create', ['record' => $record], null, $after);
    }

    /** POST dns-manager/records/{id} {record, modified_on, confirm, confirm_name} */
    public function update(ApiRequest $r): array
    {
        $this->guardWrite($r);
        $id     = $this->recordId($r);
        $record = $this->input($r);
        $zone   = $this->zone();
        $host   = $this->siteHost();
        $before = $this->find($id);
        $after  = array_merge($record, ['name' => Guard::fqdn($record['name'], $zone)]);

        $this->protect($r, 'update', $before, $after, $zone, $host);

        return $this->send($r, 'update', ['id' => $id, 'record' => $record, 'modified_on' => $r->string('modified_on')], $before, $after);
    }

    /** POST dns-manager/records/{id}/delete {modified_on, confirm, confirm_name} */
    public function delete(ApiRequest $r): array
    {
        $this->guardWrite($r);
        $id     = $this->recordId($r);
        $zone   = $this->zone();
        $before = $this->find($id);
        $this->protect($r, 'delete', $before, null, $zone, $this->siteHost());

        return $this->send($r, 'delete', ['id' => $id, 'modified_on' => $r->string('modified_on') ?: (string) ($before['modified_on'] ?? '')], $before, null);
    }

    /** POST dns-manager/revert {log_id, confirm} - undoes a change from UX1 Network app log. */
    public function revert(ApiRequest $r): array
    {
        $this->guardWrite($r);
        $logId = $r->int('log_id');
        if ($logId <= 0) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_LOG_ID'), 422, 'invalid_log_id');
        }

        $zone = $this->zone();
        $host = $this->siteHost();
        $row  = $this->logRow($logId);
        // Same rules as for a direct change: UX1 Network app turns the history item into a delete / create / update.
        $plan = $this->revertPlan($row, $zone, $host);
        if ($plan === null) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NOT_REVERTABLE'), 422, 'not_revertable');
        }
        if ($plan['current'] !== null) {
            $this->protect($r, 'revert', $plan['current'], $plan['target'], $zone, $host);
        }

        return $this->send($r, 'revert', ['log_id' => $logId], $plan['current'], $plan['target']);
    }

    /** GET dns-manager/config - the safety switches and the administrators who can be chosen (core.admin only). */
    public function getConfig(ApiRequest $r): array
    {
        return [
            'manage_user_ids'       => $this->managerIds(),
            'allow_critical_delete' => $this->allowCritical(),
            'admins'                => $this->adminOptions(),
        ];
    }

    /** POST dns-manager/config {manage_user_ids, allow_critical_delete, password} (core.admin + password). */
    public function saveConfig(ApiRequest $r): array
    {
        $this->verifyPassword($r);
        $admins = $this->adminOptions();
        $ids    = [];
        foreach ((array) $r->input('manage_user_ids', []) as $id) {
            if (isset($admins[(string) (int) $id])) {
                $ids[] = (int) $id;
            }
        }
        $ids    = array_values(array_unique($ids));
        $before = ['manage_user_ids' => $this->managerIds(), 'allow_critical_delete' => $this->allowCritical()];
        $after  = ['manage_user_ids' => $ids, 'allow_critical_delete' => $r->bool('allow_critical_delete')];
        $store  = $this->ctx()->store();
        $store->set('manage_user_ids', $after['manage_user_ids']);
        $store->set('allow_critical_delete', $after['allow_critical_delete']);
        $this->allowCritical = $after['allow_critical_delete'];
        $this->journal()->add($r->user(), 'config', 'success', $before, $after);

        return $after + ['admins' => $admins];
    }

    // ---------------------------------------------------------------- internals

    /** Checks the password of the logged-in user (rate limited, failures are logged). */
    private function verifyPassword(ApiRequest $r): void
    {
        $user = $r->user();
        $wait = (new RateLimiter($this->ctx()->db()))->hit('dns-manager:reauth:' . (int) $user->id, 5, 900);
        if ($wait > 0) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_RATE'), 429, 'rate_limited', null, ['Retry-After' => (string) $wait]);
        }
        $hash = (string) ($user->password ?? '');
        if ($hash === '') {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NO_PASSWORD'), 409, 'reauth_unavailable');
        }
        if (!UserHelper::verifyPassword($r->string('password'), $hash, (int) $user->id)) {
            $this->journal()->add($user, 'reauth', 'denied', null, null, '', 'wrong password');
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_PASSWORD'), 403, 'wrong_password');
        }
    }

    /**
     * Safety net of a change of an existing record ($current -> $target; $target null = delete): refuses to remove or move
     * a critical record unless allowed, and wants the full name retyped for MX / website / e-mail authentication records.
     *
     * @param array<string,mixed>      $current
     * @param array<string,mixed>|null $target
     */
    private function protect(ApiRequest $r, string $action, array $current, ?array $target, string $zone, string $host): void
    {
        $plan = $this->plan($current, $target, $zone, $host);
        if ($plan['blocked']) {
            $this->deny($r, $action, $current, $target, $target === null ? 'UXS_DNS_MANAGER_ERR_CRITICAL_DELETE' : 'UXS_DNS_MANAGER_ERR_CRITICAL_MOVE');
        }
        if ($plan['name'] !== '') {
            $this->requireName($r, $action, $current, $target, $zone);
        }
    }

    /**
     * @param array<string,mixed>      $current
     * @param array<string,mixed>|null $target
     * @return array{risks:list<string>,name:string,blocked:bool}
     */
    private function plan(array $current, ?array $target, string $zone, string $host): array
    {
        $currentRisk = Guard::risks($current, $zone, $host);
        $risks       = $currentRisk;
        $moved       = true;
        if ($target !== null) {
            $risks = array_values(array_unique(array_merge($currentRisk, Guard::risks($target, $zone, $host))));
            $moved = strtoupper((string) ($current['type'] ?? '')) !== strtoupper((string) ($target['type'] ?? ''))
                || Guard::fqdn((string) ($current['name'] ?? ''), $zone) !== Guard::fqdn((string) ($target['name'] ?? ''), $zone);
        }
        $needName = $target === null ? $risks !== [] : array_intersect($risks, ['mx', 'web', 'email_auth']) !== [];

        return [
            'risks'   => $risks,
            'name'    => $needName ? Guard::fqdn((string) ($current['name'] ?? ''), $zone) : '',
            'blocked' => $moved && Guard::isCritical($currentRisk) && !$this->allowCritical(),
        ];
    }

    /**
     * What undoing UX1 Network app history item does, the way UX1 Network app derives it (before null = delete the created
     * record, after null = recreate the deleted one, otherwise write the old values back). Null = not revertable.
     *
     * @param array<string,mixed> $row
     * @return array{current:?array<string,mixed>,target:?array<string,mixed>,risks:list<string>,name:string,blocked:bool}|null
     */
    private function revertPlan(array $row, string $zone, string $host): ?array
    {
        if (($row['status'] ?? '') !== 'success' || !empty($row['reverted_by'])) {
            return null;
        }
        $before = \is_array($row['before'] ?? null) ? $row['before'] : null;
        $after  = \is_array($row['after'] ?? null) ? $row['after'] : null;
        if ($after === null) {
            // Recreating a deleted record cannot remove anything.
            return $before === null ? null : ['current' => null, 'target' => $before, 'risks' => Guard::risks($before, $zone, $host), 'name' => '', 'blocked' => false];
        }

        return ['current' => $after, 'target' => $before] + $this->plan($after, $before, $zone, $host);
    }

    /** One item of UX1 Network app history by id (the broker has no lookup by id, so the newest 1000 items are scanned). @return array<string,mixed> */
    private function logRow(int $id): array
    {
        for ($page = 1; $page <= 10; $page++) {
            $res  = $this->broker('GET', 'log', ['page_no' => $page, 'per_page' => 100]);
            $rows = (array) ($res['rows'] ?? []);
            foreach ($rows as $row) {
                if (\is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            if (\count($rows) < 100) {
                break;
            }
        }
        throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_LOG_NOT_FOUND'), 404, 'log_not_found');
    }

    /** Common checks of every write, in the order that explains the problem best. */
    private function guardWrite(ApiRequest $r): void
    {
        $this->requireManager($r);
        $conn = $this->connection();
        if ($conn['state'] !== 'active') {
            throw new ApiException($conn['message'] !== '' ? $conn['message'] : Text::_('UXS_DNS_MANAGER_ERR_NOT_ACTIVE'), 409, 'not_active');
        }
        $wait = (new RateLimiter($this->ctx()->db()))->hit('dns-manager:write:' . (int) $r->user()->id, 40, 60);
        if ($wait > 0) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_RATE'), 429, 'rate_limited', null, ['Retry-After' => (string) $wait]);
        }
        if (!$this->journal()->acknowledged((int) $r->user()->id)) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_ACK'), 403, 'ack_required');
        }
        if ($this->reauthLeft() <= 0) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_REAUTH'), 403, 'reauth_required');
        }
        if (!$r->bool('confirm')) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_CONFIRM'), 422, 'confirm_required');
        }
    }

    private function requireManager(ApiRequest $r): void
    {
        if (!$this->canManage($r)) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NOT_ALLOWED'), 403, 'not_allowed');
        }
    }

    /** core.admin AND listed in the module settings (default: nobody can edit). */
    private function canManage(ApiRequest $r): bool
    {
        $user = $r->user();
        if (!$user->authorise('core.admin')) {
            return false;
        }
        return \in_array((int) $user->id, $this->managerIds(), true);
    }

    /** @return list<int> */
    private function managerIds(): array
    {
        return array_values(array_map('intval', (array) $this->ctx()->store()->get('manage_user_ids', [])));
    }

    private function allowCritical(): bool
    {
        return $this->allowCritical ??= (bool) $this->ctx()->store()->get('allow_critical_delete', false);
    }

    /** @return array<string,mixed> */
    private function input(ApiRequest $r): array
    {
        try {
            return Guard::record((array) $r->input('record', []));
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(Text::_($e->getMessage()), 422, 'invalid_record');
        }
    }

    private function recordId(ApiRequest $r): string
    {
        $id = $r->param('id');
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $id) !== 1) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_ID'), 422, 'invalid_id');
        }

        return $id;
    }

    /** The current (fresh) version of a record, 404 when it is not in the zone. @return array<string,mixed> */
    private function find(string $id): array
    {
        $res = $this->broker('GET', 'list', ['fresh' => 1]);
        foreach ((array) ($res['records'] ?? []) as $rec) {
            if (\is_array($rec) && (string) ($rec['id'] ?? '') === $id) {
                return $rec;
            }
        }
        throw new ApiException(Text::_('UXS_DNS_MANAGER_ERR_NOT_FOUND'), 404, 'record_not_found');
    }

    /** The user must retype the full record name before a risky change. */
    private function requireName(ApiRequest $r, string $action, array $before, ?array $after, string $zone): void
    {
        $want  = Guard::fqdn((string) $before['name'], $zone);
        $typed = strtolower(rtrim(trim($r->string('confirm_name')), '.'));
        if ($typed === '' || $typed !== $want) {
            $this->deny($r, $action, $before, $after, 'UXS_DNS_MANAGER_ERR_CONFIRM_NAME', 'name_required');
        }
    }

    /** @return never */
    private function deny(ApiRequest $r, string $action, ?array $before, ?array $after, string $key, string $code = 'critical_protected'): void
    {
        $msg = Text::_($key);
        $this->journal()->add($r->user(), $action, 'denied', $before, $after, '', $msg);

        throw new ApiException($msg, $code === 'name_required' ? 422 : 403, $code);
    }

    /**
     * Calls the broker for a write and logs the outcome locally.
     *
     * @param array<string,mixed>      $body
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @return array<string,mixed>
     */
    private function send(ApiRequest $r, string $op, array $body, ?array $before, ?array $after): array
    {
        $user = $r->user();
        try {
            $res = $this->broker('POST', $op, [], $body + [
                'actor'            => $this->actor($r),
                'site_admin_email' => (string) $this->ctx()->app()->get('mailfrom', ''),
            ]);
        } catch (ApiException $e) {
            if ($e->errorCode() === 'reauth_required') {
                // UX1 Network app did not accept the confirmation: forget it so the dialog asks for the password again.
                $this->ctx()->app()->getSession()->set(self::SESSION_REAUTH, 0);
            }
            $this->journal()->add($user, $op, $e->status() === 409 ? 'conflict' : 'error', $before, $after, '', $e->getMessage());
            throw $e;
        }
        $logged = false;
        foreach ((array) ($res['results'] ?? []) as $item) {
            if (\is_array($item) && ($item['status'] ?? '') === 'success') {
                $this->journal()->add(
                    $user,
                    $op,
                    'success',
                    \is_array($item['before'] ?? null) ? $item['before'] : null,
                    \is_array($item['record'] ?? null) ? $item['record'] : null,
                    (string) ($res['batch_id'] ?? '')
                );
                $logged = true;
            }
        }
        if (!$logged) {
            $this->journal()->add($user, $op, 'success', $before, $after, (string) ($res['batch_id'] ?? ''));
        }
        unset($res['success']);

        return $res;
    }

    private function zone(): string
    {
        return (string) $this->connection()['zone'];
    }

    /** Host of this site (lower case). */
    private function siteHost(): string
    {
        return strtolower((string) parse_url(Uri::root(), PHP_URL_HOST));
    }

    /** @return array<string,mixed> */
    private function actor(ApiRequest $r): array
    {
        $u = $r->user();

        return [
            'id'        => (int) $u->id,
            'login'     => (string) $u->username,
            'name'      => (string) $u->name,
            'email'     => (string) $u->email,
            'ip'        => ClientIp::current(),
            'ua'        => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'auth_time' => $this->reauthTime(),
        ];
    }

    private function reauthTime(): int
    {
        return (int) $this->ctx()->app()->getSession()->get(self::SESSION_REAUTH, 0);
    }

    /** Seconds the password confirmation stays valid (0 = confirm again). */
    private function reauthLeft(): int
    {
        $t = $this->reauthTime();

        return $t > 0 ? max(0, self::REAUTH_SECONDS - (time() - $t)) : 0;
    }

    private function journal(): Journal
    {
        return new Journal($this->ctx()->db());
    }

    /**
     * State of the link to UX1 Network app, resolved once per request.
     *
     * @return array{state:string,reason:string,message:string,zone:string}
     */
    private function connection(): array
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $out = ['state' => 'active', 'reason' => '', 'message' => '', 'zone' => ''];
        if (!$this->ctx()->kernel()->registry()->isEnabled('ca-connector')) {
            return $this->connection = ['state' => 'connector_off', 'reason' => '', 'message' => Text::_('UXS_DNS_MANAGER_STATE_CONNECTOR_OFF'), 'zone' => ''];
        }
        if ($this->client() === null) {
            return $this->connection = ['state' => 'not_paired', 'reason' => '', 'message' => Text::_('UXS_DNS_MANAGER_STATE_NOT_PAIRED'), 'zone' => ''];
        }
        try {
            $res = $this->broker('GET', 'status');
            if (!empty($res['active'])) {
                $out['zone'] = (string) ($res['zone'] ?? '');
            } else {
                $reason = preg_replace('/[^a-z_]/', '', (string) ($res['reason'] ?? '')) ?: 'not_configured';
                $out    = ['state' => 'inactive', 'reason' => $reason, 'message' => Text::_('UXS_DNS_MANAGER_REASON_' . strtoupper($reason)), 'zone' => ''];
            }
        } catch (ApiException $e) {
            $out = ['state' => $e->errorCode() === 'ca_unreachable' ? 'ca_unreachable' : 'ca_error', 'reason' => $e->errorCode(), 'message' => $e->getMessage(), 'zone' => ''];
        }

        return $this->connection = $out;
    }

    private function client(): ?CaClient
    {
        try {
            return class_exists(CaClient::class) ? CaClient::fromContext($this->ctx()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * One signed call to the broker.
     *
     * @param array<string,scalar> $query
     * @param array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function broker(string $method, string $action, array $query = [], array $body = []): array
    {
        $client = $this->client();
        if ($client === null) {
            throw new ApiException(Text::_('UXS_DNS_MANAGER_STATE_NOT_PAIRED'), 424, 'not_paired');
        }
        try {
            $res = $method === 'GET'
                ? $client->get('dns_manage', ['action' => $action] + $query)
                : $client->post('dns_manage', ['action' => $action] + $query, $body);
        } catch (\RuntimeException $e) {
            throw $this->mapError($e);
        }
        if (empty($res['success'])) {
            throw new ApiException((string) ($res['error'] ?? Text::_('UXS_DNS_MANAGER_ERR_CA')), 502, 'ca_error');
        }

        return $res;
    }

    private function mapError(\RuntimeException $e): ApiException
    {
        $msg = $e->getMessage();
        if (preg_match('/UX1 Network app HTTP (\d{3})(?:: (.*))?$/s', $msg, $m) === 1) {
            $status = (int) $m[1];
            $detail = trim($m[2] ?? '');
            $text   = $detail !== '' ? $detail : sprintf(Text::_('UXS_DNS_MANAGER_ERR_CA_HTTP'), $status);
            $reauth = $status === 403 && (str_contains($detail, 'znovu přihlaste') || str_contains($detail, 'reauth_required'));
            $code   = $reauth ? 'reauth_required' : match ($status) {
                409     => 'conflict',
                403     => 'ca_forbidden',
                422     => 'invalid_record',
                429     => 'rate_limited',
                default => 'ca_error',
            };

            return new ApiException($text, $status >= 400 && $status < 600 ? $status : 502, $code);
        }

        return new ApiException(Text::_('UXS_DNS_MANAGER_STATE_CA_UNREACHABLE'), 502, 'ca_unreachable');
    }

    /** @return array<string,string> user id => label of Super User style administrators (core.admin) */
    private function adminOptions(): array
    {
        try {
            $db     = $this->ctx()->db();
            $groups = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__usergroups')))->loadColumn() ?: [];
            $admins = array_values(array_filter(array_map('intval', $groups), static fn (int $g): bool => Access::checkGroup($g, 'core.admin', 'root.1') === true));
            if ($admins === []) {
                return [];
            }
            $query = $db->createQuery()
                ->select('DISTINCT ' . implode(', ', $db->quoteName(['u.id', 'u.name', 'u.username'])))
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
                ->where($db->quoteName('m.group_id') . ' IN (' . implode(',', $admins) . ')')
                ->where($db->quoteName('u.block') . ' = 0')
                ->order($db->quoteName('u.name'));
            $out = [];
            foreach ($db->setQuery($query, 0, 200)->loadObjectList() ?: [] as $u) {
                $out[(string) $u->id] = $u->name . ' (' . $u->username . ')';
            }

            return $out;
        } catch (\Throwable $e) {
            Log::add('dns-manager: listing administrators failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');

            return [];
        }
    }
}

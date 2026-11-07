<?php

namespace Ux1\UxStudio\Modules\DnsManager;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Local audit log of DNS changes (#__uxstudio_dns_log). The Central App keeps the authoritative log (with the IP address);
 * this copy is what the administrator still sees when the Central App is down, and it holds the "risks acknowledged" marks.
 */
final class Journal
{
    private const TABLE = '#__uxstudio_dns_log';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function add(object $user, string $action, string $status, ?array $before, ?array $after, string $batch = '', string $error = ''): void
    {
        $ref = $after ?? $before ?? [];
        $row = (object) [
            'created'     => Factory::getDate()->toSql(),
            'batch_id'    => substr($batch, 0, 32),
            'user_id'     => (int) ($user->id ?? 0),
            'user_login'  => mb_substr((string) ($user->username ?? ''), 0, 150),
            'user_name'   => mb_substr((string) ($user->name ?? ''), 0, 190),
            'action'      => substr($action, 0, 16),
            'status'      => substr($status, 0, 10),
            'record_id'   => substr((string) ($ref['id'] ?? ''), 0, 64),
            'record_type' => substr((string) ($ref['type'] ?? ''), 0, 10),
            'record_name' => substr((string) ($ref['name'] ?? ''), 0, 253),
            'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'after_json'  => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error'       => mb_substr($error, 0, 500),
        ];
        $this->db->insertObject(self::TABLE, $row);
    }

    public function acknowledged(int $userId): bool
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('user_id') . ' = :u')->where($db->quoteName('action') . ' = ' . $db->quote('acknowledged'))
            ->bind(':u', $userId, ParameterType::INTEGER);

        return (int) $db->setQuery($q)->loadResult() > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->order($db->quoteName('id') . ' DESC'), 0, $limit)->loadAssocList() ?: [];

        return array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'created_at'  => (string) $r['created'],
                'user_id'     => (int) $r['user_id'],
                'user_login'  => (string) $r['user_login'],
                'user_name'   => (string) $r['user_name'],
                'action'      => (string) $r['action'],
                'record_type' => (string) $r['record_type'],
                'record_name' => (string) $r['record_name'],
                'before'      => $r['before_json'] ? json_decode((string) $r['before_json'], true) : null,
                'after'       => $r['after_json'] ? json_decode((string) $r['after_json'], true) : null,
                'status'      => (string) $r['status'],
                'error'       => (string) $r['error'],
            ];
        }, $rows);
    }

    /** Successful changes in the last N days (dashboard widget). */
    public function changesSince(int $days): int
    {
        $db   = $this->db;
        $from = Factory::getDate('-' . $days . ' days')->toSql();
        $q    = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('created') . ' >= :f')->where($db->quoteName('status') . ' = ' . $db->quote('success'))
            ->where($db->quoteName('action') . ' IN (' . implode(',', array_map([$db, 'quote'], ['create', 'update', 'delete', 'revert'])) . ')')
            ->bind(':f', $from);

        return (int) $db->setQuery($q)->loadResult();
    }
}

<?php

namespace Ux1\UxStudio\Modules\EmailLog;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/** Read side of the e-mail log table. */
final class LogStore
{
    private const LIST_COLUMNS = ['id', 'created', 'to_email', 'from_email', 'subject', 'status', 'error_message', 'source', 'mailer'];

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param array{status?:string,search?:string,date_from?:string,date_to?:string} $filter status: success | error | pending
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function list(int $page, int $perPage, array $filter = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $db      = $this->db;

        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_email_log'));
        $this->applyFilter($count, $filter);
        $total = (int) $db->setQuery($count)->loadResult();

        $query = $db->createQuery()->select($db->quoteName(self::LIST_COLUMNS))->from($db->quoteName('#__uxstudio_email_log'))
            ->order($db->quoteName('id') . ' DESC');
        $this->applyFilter($query, $filter);
        $rows = $db->setQuery($query, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];

        return ['items' => array_map([self::class, 'normalize'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_email_log'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();
        if (!$row) {
            return null;
        }
        $row = self::normalize($row);
        $row['attachments'] = self::decode((string) ($row['attachments'] ?? ''), []);
        $row['attempts']    = self::decode((string) ($row['attempts'] ?? ''), []);
        $row['meta']        = self::decode((string) ($row['meta'] ?? ''), []);

        return $row;
    }

    public function delete(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_email_log'))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return $db->getAffectedRows() > 0;
    }

    public function clear(): void
    {
        $this->db->setQuery('TRUNCATE TABLE ' . $this->db->quoteName('#__uxstudio_email_log'))->execute();
    }

    /** @return array{total:int,success:int,error:int,pending:int,last_24h:int,failed_24h:int,last:?string} */
    public function stats(): array
    {
        $db    = $this->db;
        $since = Factory::getDate('-24 hours')->toSql();
        $row   = $db->setQuery(
            $db->createQuery()->select([
                'COUNT(*) AS total',
                "SUM(status = 'success') AS success",
                "SUM(status = 'error') AS error",
                "SUM(status = 'pending') AS pending",
                'SUM(created >= :since) AS last_24h',
                "SUM(created >= :since2 AND status = 'error') AS failed_24h",
                'MAX(created) AS last',
            ])->from($db->quoteName('#__uxstudio_email_log'))->bind(':since', $since)->bind(':since2', $since)
        )->loadAssoc() ?: [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'success'    => (int) ($row['success'] ?? 0),
            'error'      => (int) ($row['error'] ?? 0),
            'pending'    => (int) ($row['pending'] ?? 0),
            'last_24h'   => (int) ($row['last_24h'] ?? 0),
            'failed_24h' => (int) ($row['failed_24h'] ?? 0),
            'last'       => isset($row['last']) ? (string) $row['last'] : null,
        ];
    }

    /**
     * Rows for the Central App (status vocabulary sent | failed | pending, created_at key as in the WordPress version).
     *
     * @param array{limit?:mixed,status?:mixed,search?:mixed,date_from?:mixed,date_to?:mixed} $args
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function forHub(array $args): array
    {
        $map    = ['sent' => 'success', 'failed' => 'error', 'pending' => 'pending'];
        $status = (string) ($args['status'] ?? '');
        $filter = [
            'status'    => $map[$status] ?? '',
            'search'    => (string) ($args['search'] ?? ''),
            'date_from' => (string) ($args['date_from'] ?? ''),
            'date_to'   => (string) ($args['date_to'] ?? ''),
        ];
        $limit  = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $result = $this->list(1, $limit, $filter);

        $reverse = ['success' => 'sent', 'error' => 'failed', 'pending' => 'pending'];
        $items   = [];
        foreach ($result['items'] as $row) {
            $items[] = [
                'id'            => (int) $row['id'],
                'created_at'    => (string) $row['created'],
                'to_email'      => (string) $row['to_email'],
                'subject'       => (string) $row['subject'],
                'status'        => $reverse[$row['status']] ?? (string) $row['status'],
                'error_message' => $row['error_message'],
                'source'        => (string) $row['source'],
            ];
        }

        return ['items' => $items, 'total' => $result['total']];
    }

    /** @return array<int,array<string,mixed>> newest first, without bodies (CSV export) */
    public function export(int $max = 10000): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select($db->quoteName(self::LIST_COLUMNS))->from($db->quoteName('#__uxstudio_email_log'))->order($db->quoteName('id') . ' DESC'),
            0,
            $max
        )->loadAssocList() ?: [];
    }

    private function applyFilter(\Joomla\Database\QueryInterface $query, array $filter): void
    {
        $db = $this->db;
        $status = (string) ($filter['status'] ?? '');
        if (\in_array($status, ['success', 'error', 'pending'], true)) {
            $query->where($db->quoteName('status') . ' = :f_status')->bind(':f_status', $status);
        }
        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where('(' . $db->quoteName('to_email') . ' LIKE :f_s1 OR ' . $db->quoteName('subject') . ' LIKE :f_s2 OR '
                . $db->quoteName('source') . ' LIKE :f_s3 OR ' . $db->quoteName('error_message') . ' LIKE :f_s4)')
                ->bind(':f_s1', $like)->bind(':f_s2', $like)->bind(':f_s3', $like)->bind(':f_s4', $like);
        }
        $from = trim((string) ($filter['date_from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $fromTs = $from . ' 00:00:00';
            $query->where($db->quoteName('created') . ' >= :f_from')->bind(':f_from', $fromTs);
        }
        $to = trim((string) ($filter['date_to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $toTs = $to . ' 23:59:59';
            $query->where($db->quoteName('created') . ' <= :f_to')->bind(':f_to', $toTs);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];

        return $row;
    }

    private static function decode(string $json, mixed $default): mixed
    {
        $v = json_decode($json, true);

        return \is_array($v) ? $v : $default;
    }
}

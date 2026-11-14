<?php

namespace Ux1\UxStudio\Modules\ServiceRequests;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Persistence of service requests and of the outbox of client replies. All dates are UTC, every query is parameterised.
 */
final class Store
{
    public const TABLE  = '#__uxstudio_service_requests';
    public const OUTBOX = '#__uxstudio_service_request_outbox';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @param array<string,string|int|null> $data columns of the request (names are fixed by the caller) @return int new id */
    public function insert(array $data): int
    {
        $db   = $this->db;
        $q    = $db->createQuery()->insert($db->quoteName(self::TABLE))->columns($db->quoteName(array_keys($data)));
        $vals = [];
        $bound = array_values($data); // bind() keeps references: every value needs its own variable
        foreach ($bound as $i => $_) {
            $vals[] = ':v' . $i;
            $q->bind(':v' . $i, $bound[$i], $bound[$i] === null ? ParameterType::NULL : (\is_int($bound[$i]) ? ParameterType::INTEGER : ParameterType::STRING));
        }
        $q->values(implode(',', $vals));
        $db->setQuery($q)->execute();

        return (int) $db->insertid();
    }

    /** @param array<string,string|int|null> $data */
    public function update(int $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $db = $this->db;
        $q  = $db->createQuery()->update($db->quoteName(self::TABLE));
        $cols  = array_keys($data);
        $bound = array_values($data); // bind() keeps references: every value needs its own variable
        foreach ($cols as $i => $col) {
            $q->set($db->quoteName((string) $col) . ' = :v' . $i);
            $q->bind(':v' . $i, $bound[$i], $bound[$i] === null ? ParameterType::NULL : (\is_int($bound[$i]) ? ParameterType::INTEGER : ParameterType::STRING));
        }
        $q->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($q)->execute();
    }

    /** @return array<string,mixed>|null raw row */
    public function row(int $id): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> raw rows, newest first */
    public function rows(string $status = ''): array
    {
        $db = $this->db;
        $q  = $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->order($db->quoteName('id') . ' DESC');
        if ($status !== '') {
            $q->where($db->quoteName('status') . ' = :s')->bind(':s', $status);
        }

        return $db->setQuery($q, 0, 500)->loadAssocList() ?: [];
    }

    public function delete(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::OUTBOX))->where($db->quoteName('request_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        return $db->getAffectedRows() > 0;
    }

    /** Requests UX1 Network app does not know yet and that may still be retried. @return array<int,array<string,mixed>> */
    public function unsynced(int $maxAttempts, int $limit): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('central_ticket_id') . ' = 0')
                ->where($db->quoteName('sync_state') . ' <> ' . $db->quote('error'))
                ->where($db->quoteName('sync_attempts') . ' < :a')->bind(':a', $maxAttempts, ParameterType::INTEGER)
                ->order($db->quoteName('id') . ' ASC'),
            0,
            $limit
        )->loadAssocList() ?: [];
    }

    /** Ids of requests whose conversation can still change, least recently refreshed first. @return int[] */
    public function openIds(int $limit): array
    {
        $db = $this->db;

        return array_map('intval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('central_ticket_id') . ' > 0')
                ->where('(' . $db->quoteName('central_status') . ' IS NULL OR ' . $db->quoteName('central_status') . ' NOT IN (' . $db->quote('closed') . ',' . $db->quote('rejected') . '))')
                ->order('COALESCE(' . $db->quoteName('synced_at') . ', ' . $db->quoteName('created') . ') ASC'),
            0,
            $limit
        )->loadColumn() ?: []);
    }

    /** @return array{total:int,open:int,waiting_client:int,done:int,failed:int} */
    public function counts(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('central_status'), $db->quoteName('status'), $db->quoteName('sync_state'), 'COUNT(*) AS n'])
                ->from($db->quoteName(self::TABLE))->group([$db->quoteName('central_status'), $db->quoteName('status'), $db->quoteName('sync_state')])
        )->loadAssocList() ?: [];
        $out = ['total' => 0, 'open' => 0, 'waiting_client' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $n   = (int) $r['n'];
            $cs  = (string) $r['central_status'];
            $out['total'] += $n;
            if ($r['sync_state'] === 'error') {
                $out['failed'] += $n;
            }
            if ($cs === 'waiting_client') {
                $out['waiting_client'] += $n;
            }
            if (\in_array($cs, ['resolved', 'closed', 'rejected'], true) || ($cs === '' && $r['status'] === 'done')) {
                $out['done'] += $n;
            } else {
                $out['open'] += $n;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------- outbox */

    public function queueReply(int $requestId, string $externalId, string $body, string $name, string $email): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName(self::OUTBOX))
                ->columns($db->quoteName(['request_id', 'external_id', 'body', 'author_name', 'author_email', 'created']))
                ->values(':r, :x, :b, :n, :e, :c')
                ->bind(':r', $requestId, ParameterType::INTEGER)->bind(':x', $externalId)->bind(':b', $body)->bind(':n', $name)->bind(':e', $email)->bind(':c', $now)
        )->execute();
    }

    /** Queued replies below the attempt limit, joined with UX1 Network app ticket id. @return array<int,array<string,mixed>> */
    public function outbox(int $maxAttempts, int $limit): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select(['o.*', $db->quoteName('r.central_ticket_id')])
                ->from($db->quoteName(self::OUTBOX, 'o'))->join('INNER', $db->quoteName(self::TABLE, 'r') . ' ON r.id = o.request_id')
                ->where($db->quoteName('o.attempts') . ' < :a')->bind(':a', $maxAttempts, ParameterType::INTEGER)
                ->order($db->quoteName('o.id') . ' ASC'),
            0,
            $limit
        )->loadAssocList() ?: [];
    }

    public function outboxFailed(int $id, int $attempts, string $error): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $err = mb_substr($error, 0, 500);
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::OUTBOX))
                ->set($db->quoteName('attempts') . ' = :a')->set($db->quoteName('last_error') . ' = :e')->set($db->quoteName('last_try_at') . ' = :t')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':a', $attempts, ParameterType::INTEGER)->bind(':e', $err)->bind(':t', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    public function outboxDelete(int $id): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName(self::OUTBOX))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();
    }

    /** Number of replies still waiting for delivery (below the attempt limit), per request id. @return array<int,int> */
    public function pendingReplies(int $maxAttempts): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('request_id'), 'COUNT(*) AS n'])->from($db->quoteName(self::OUTBOX))
                ->where($db->quoteName('attempts') . ' < :a')->bind(':a', $maxAttempts, ParameterType::INTEGER)->group($db->quoteName('request_id'))
        )->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['request_id']] = (int) $r['n'];
        }

        return $out;
    }

    /** Replies that ran out of attempts: count and the last error, per request id. @return array<int,array{n:int,error:string}> */
    public function failedReplies(int $maxAttempts): array
    {
        $db   = $this->db;
        $rows = $db->setQuery(
            $db->createQuery()->select([$db->quoteName('request_id'), $db->quoteName('last_error')])->from($db->quoteName(self::OUTBOX))
                ->where($db->quoteName('attempts') . ' >= :a')->bind(':a', $maxAttempts, ParameterType::INTEGER)->order($db->quoteName('id') . ' ASC')
        )->loadAssocList() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $rid       = (int) $r['request_id'];
            $out[$rid] = ['n' => ($out[$rid]['n'] ?? 0) + 1, 'error' => (string) $r['last_error']];
        }

        return $out;
    }

    /** Gives every queued reply of a request a fresh set of attempts (the user pressed "refresh"). */
    public function outboxReset(int $requestId): void
    {
        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName(self::OUTBOX))
                ->set($db->quoteName('attempts') . ' = 0')->set($db->quoteName('last_try_at') . ' = NULL')
                ->where($db->quoteName('request_id') . ' = :id')->bind(':id', $requestId, ParameterType::INTEGER)
        )->execute();
    }
}

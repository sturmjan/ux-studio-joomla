<?php

namespace Ux1\UxStudio\Modules\CaConnector;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ClientIp;

\defined('_JEXEC') or die;

/**
 * Persistence of the connector: small key/value state, one-time nonces, SSO tokens and the audit log.
 * All dates are UTC. Every query is parameterised.
 */
final class Store
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /* ---------------------------------------------------------------- key/value state */

    public function get(string $key, string $default = ''): string
    {
        $db  = $this->db;
        $val = $db->setQuery(
            $db->createQuery()->select($db->quoteName('v'))->from($db->quoteName('#__uxstudio_caconn_kv'))
                ->where($db->quoteName('k') . ' = :k')->bind(':k', $key)
        )->loadResult();

        return $val === null ? $default : (string) $val;
    }

    public function set(string $key, string $value): void
    {
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__uxstudio_caconn_kv') . ' (' . $db->quoteName('k') . ', ' . $db->quoteName('v') . ') VALUES (' . $db->quote($key) . ', ' . $db->quote($value) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . $db->quoteName('v') . ' = VALUES(' . $db->quoteName('v') . ')'
        )->execute();
    }

    public function delete(string $key): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_caconn_kv'))->where($db->quoteName('k') . ' = :k')->bind(':k', $key))->execute();
    }

    /* ---------------------------------------------------------------- nonces (anti replay) */

    /** Marks a request nonce as used. @return bool false when it was already used (replay) */
    public function consumeNonce(string $nonce): bool
    {
        $db   = $this->db;
        $hash = hash('sha256', $nonce);
        $now  = Factory::getDate()->toSql();
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName('#__uxstudio_caconn_nonce'))
                    ->columns($db->quoteName(['nonce_hash', 'created']))->values(':h, :c')->bind(':h', $hash)->bind(':c', $now)
            )->execute();
        } catch (\Throwable $e) {
            return false; // primary key collision = already seen
        }
        if (random_int(1, 50) === 1) {
            $old = Factory::getDate('-1 hour')->toSql();
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_caconn_nonce'))->where($db->quoteName('created') . ' < :o')->bind(':o', $old))->execute();
        }

        return true;
    }

    /* ---------------------------------------------------------------- SSO tokens */

    /** @param array<string,mixed> $data @return string expiry (UTC datetime) */
    public function createSsoToken(string $token, array $data, int $ttl): string
    {
        $db      = $this->db;
        $hash    = hash('sha256', $token);
        $now     = Factory::getDate()->toSql();
        $expires = Factory::getDate('+' . max(10, $ttl) . ' seconds')->toSql();
        $email   = (string) $data['operator_email'];
        $opId    = (int) $data['operator_id'];
        $crole   = (string) ($data['central_role'] ?? '');
        $trole   = (string) $data['target_role'];
        $userId  = (int) ($data['user_id'] ?? 0);
        $ua      = (string) ($data['ua_hash'] ?? '');
        $ip      = (string) ($data['ip'] ?? '');
        $ret     = (string) ($data['return_to'] ?? '');
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_caconn_sso'))
                ->columns($db->quoteName(['token_hash', 'operator_email', 'operator_id', 'central_role', 'target_role', 'user_id', 'ua_hash', 'ip', 'return_to', 'created', 'expires']))
                ->values(':h, :e, :o, :cr, :tr, :u, :ua, :ip, :rt, :c, :x')
                ->bind(':h', $hash)->bind(':e', $email)->bind(':o', $opId, ParameterType::INTEGER)->bind(':cr', $crole)->bind(':tr', $trole)
                ->bind(':u', $userId, ParameterType::INTEGER)->bind(':ua', $ua)->bind(':ip', $ip)->bind(':rt', $ret)->bind(':c', $now)->bind(':x', $expires)
        )->execute();

        return $expires;
    }

    /** @return array<string,mixed>|null */
    public function findSsoToken(string $token): ?array
    {
        $db   = $this->db;
        $hash = hash('sha256', $token);
        $row  = $db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_caconn_sso'))->where($db->quoteName('token_hash') . ' = :h')->bind(':h', $hash)
        )->loadAssoc();

        return $row ?: null;
    }

    /** Atomically consumes a token; false when somebody else was faster or it is revoked / used. */
    public function consumeSsoToken(int $id): bool
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_caconn_sso'))->set($db->quoteName('consumed') . ' = :n')
                ->where($db->quoteName('id') . ' = :id')->where($db->quoteName('consumed') . ' IS NULL')->where($db->quoteName('revoked') . ' IS NULL')
                ->bind(':n', $now)->bind(':id', $id, ParameterType::INTEGER)
        )->execute();

        return $db->getAffectedRows() === 1;
    }

    /** Marks every outstanding (unused, unrevoked) token as revoked. @return int number revoked */
    public function revokeAllSso(): int
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_caconn_sso'))->set($db->quoteName('revoked') . ' = :n')
                ->where($db->quoteName('consumed') . ' IS NULL')->where($db->quoteName('revoked') . ' IS NULL')->bind(':n', $now)
        )->execute();

        return (int) $db->getAffectedRows();
    }

    public function countOutstandingSso(): int
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_caconn_sso'))
                ->where($db->quoteName('consumed') . ' IS NULL')->where($db->quoteName('revoked') . ' IS NULL')->where($db->quoteName('expires') . ' > :n')->bind(':n', $now)
        )->loadResult();
    }

    /* ---------------------------------------------------------------- audit log */

    public function log(string $event, bool $ok, string $detail = '', int $userId = 0): void
    {
        try {
            $db     = $this->db;
            $now    = Factory::getDate()->toSql();
            $ip     = mb_substr(ClientIp::current(), 0, 45);
            $detail = mb_substr($detail, 0, 500);
            $flag   = $ok ? 1 : 0;
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName('#__uxstudio_caconn_log'))
                    ->columns($db->quoteName(['created', 'event', 'ok', 'detail', 'ip', 'user_id']))->values(':c, :e, :o, :d, :ip, :u')
                    ->bind(':c', $now)->bind(':e', $event)->bind(':o', $flag, ParameterType::INTEGER)->bind(':d', $detail)->bind(':ip', $ip)->bind(':u', $userId, ParameterType::INTEGER)
            )->execute();
        } catch (\Throwable $e) {
            // Logging must never break the request.
        }
    }

    /** Failed events of one kind from one IP in the last $seconds. */
    public function recentFailures(string $event, string $ip, int $seconds): int
    {
        $db    = $this->db;
        $since = Factory::getDate('-' . $seconds . ' seconds')->toSql();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_caconn_log'))
                ->where($db->quoteName('event') . ' = :e')->where($db->quoteName('ok') . ' = 0')->where($db->quoteName('ip') . ' = :ip')->where($db->quoteName('created') . ' >= :s')
                ->bind(':e', $event)->bind(':ip', $ip)->bind(':s', $since)
        )->loadResult();
    }

    /** @return array<int,array<string,mixed>> */
    public function recentLog(int $limit = 50): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'created', 'event', 'ok', 'detail', 'ip', 'user_id']))
                ->from($db->quoteName('#__uxstudio_caconn_log'))->order($db->quoteName('id') . ' DESC'),
            0,
            max(1, min(200, $limit))
        )->loadAssocList() ?: [];
    }
}

<?php

namespace Ux1\UxStudio\Modules\ThirdPartyLogin;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Persistence of the module: linked provider identities and the audit log. Dates are UTC, every query is parameterised.
 */
final class Store
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array{id:int,user_id:int,provider:string,subject:string,email:string}|null */
    public function findIdentity(string $provider, string $subject): ?array
    {
        $db  = $this->db;
        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'user_id', 'provider', 'subject', 'email']))->from($db->quoteName('#__uxstudio_tpl_identities'))
                ->where($db->quoteName('provider') . ' = :p')->where($db->quoteName('subject') . ' = :s')
                ->bind(':p', $provider)->bind(':s', $subject)
        )->loadAssoc();

        return $row ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'provider' => (string) $row['provider'], 'subject' => (string) $row['subject'], 'email' => (string) $row['email']] : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function identitiesOfUser(int $userId): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'provider', 'email', 'created', 'last_login']))->from($db->quoteName('#__uxstudio_tpl_identities'))
                ->where($db->quoteName('user_id') . ' = :u')->bind(':u', $userId, ParameterType::INTEGER)->order($db->quoteName('id'))
        )->loadAssocList() ?: [];
    }

    /** True when the identity was stored (false when it already exists). */
    public function addIdentity(int $userId, string $provider, string $subject, string $email): bool
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName('#__uxstudio_tpl_identities'))
                    ->columns($db->quoteName(['user_id', 'provider', 'subject', 'email', 'created']))->values(':u, :p, :s, :e, :c')
                    ->bind(':u', $userId, ParameterType::INTEGER)->bind(':p', $provider)->bind(':s', $subject)->bind(':e', $email)->bind(':c', $now)
            )->execute();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function touch(int $id): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_tpl_identities'))->set($db->quoteName('last_login') . ' = :n')
                ->where($db->quoteName('id') . ' = :i')->bind(':n', $now)->bind(':i', $id, ParameterType::INTEGER)
        )->execute();
    }

    public function deleteIdentity(int $id): bool
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_tpl_identities'))->where($db->quoteName('id') . ' = :i')->bind(':i', $id, ParameterType::INTEGER))->execute();

        return $db->getAffectedRows() > 0;
    }

    public function deleteForUser(int $userId): void
    {
        $db = $this->db;
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__uxstudio_tpl_identities'))->where($db->quoteName('user_id') . ' = :u')->bind(':u', $userId, ParameterType::INTEGER))->execute();
    }

    /** @return array<int,array<string,mixed>> */
    public function allIdentities(int $limit = 500): array
    {
        $db = $this->db;
        $q  = $db->createQuery()
            ->select('i.id, i.user_id, i.provider, i.email, i.created, i.last_login, u.name, u.username, u.block')
            ->from($db->quoteName('#__uxstudio_tpl_identities', 'i'))
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('i.user_id'))
            ->order($db->quoteName('i.id') . ' DESC')->setLimit($limit);

        return $db->setQuery($q)->loadAssocList() ?: [];
    }

    public function countIdentities(): int
    {
        $db = $this->db;

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_tpl_identities')))->loadResult();
    }

    /** Ids of users with this e-mail address (case-insensitive; Joomla does not enforce unique e-mails in every setup). @return int[] */
    public function userIdsByEmail(string $email): array
    {
        $db    = $this->db;
        $lower = mb_strtolower($email);

        return array_map('intval', $db->setQuery(
            $db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__users'))->where('LOWER(' . $db->quoteName('email') . ') = :e')->bind(':e', $lower)
        )->loadColumn() ?: []);
    }

    public function log(string $event, string $provider, string $area, int $userId, string $detail, string $ip): void
    {
        $db  = $this->db;
        $now = Factory::getDate()->toSql();
        $detail = mb_substr($detail, 0, 250);
        try {
            $db->setQuery(
                $db->createQuery()->insert($db->quoteName('#__uxstudio_tpl_log'))
                    ->columns($db->quoteName(['created', 'event', 'provider', 'area', 'user_id', 'detail', 'ip']))->values(':c, :e, :p, :a, :u, :d, :ip')
                    ->bind(':c', $now)->bind(':e', $event)->bind(':p', $provider)->bind(':a', $area)->bind(':u', $userId, ParameterType::INTEGER)->bind(':d', $detail)->bind(':ip', $ip)
            )->execute();
        } catch (\Throwable $e) {
            // The audit log must never break a login.
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function logs(int $limit = 200): array
    {
        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'created', 'event', 'provider', 'area', 'user_id', 'detail', 'ip']))->from($db->quoteName('#__uxstudio_tpl_log'))
                ->order($db->quoteName('id') . ' DESC')->setLimit($limit)
        )->loadAssocList() ?: [];
    }
}

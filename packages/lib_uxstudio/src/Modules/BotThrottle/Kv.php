<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/** Tiny key / value store with expiry in #__uxstudio_bt_kv (rDNS answers, load tier hysteresis). Never throws. */
final class Kv
{
    private const TABLE = '#__uxstudio_bt_kv';

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function get(string $key): ?string
    {
        try {
            $db  = $this->db;
            $now = time();
            $k   = substr($key, 0, 64);
            $v   = $db->setQuery(
                $db->createQuery()->select($db->quoteName('v'))->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('k') . ' = :k')->where($db->quoteName('expires') . ' > :n')
                    ->bind(':k', $k)->bind(':n', $now, ParameterType::INTEGER)
            )->loadResult();

            return $v === null ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttl): void
    {
        try {
            $db      = $this->db;
            $k       = substr($key, 0, 64);
            $v       = substr($value, 0, 255);
            $expires = time() + max(1, $ttl);
            $query   = $db->createQuery()->setQuery(
                'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (' . implode(', ', $db->quoteName(['k', 'v', 'expires'])) . ') VALUES (:k, :v, :e) '
                . 'ON DUPLICATE KEY UPDATE ' . $db->quoteName('v') . ' = VALUES(' . $db->quoteName('v') . '), ' . $db->quoteName('expires') . ' = VALUES(' . $db->quoteName('expires') . ')'
            )->bind(':k', $k)->bind(':v', $v)->bind(':e', $expires, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // A cache miss only costs time.
        }
    }

    public function purge(): void
    {
        try {
            $db  = $this->db;
            $now = time();
            $db->setQuery($db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('expires') . ' < :n')->bind(':n', $now, ParameterType::INTEGER))->execute();
        } catch (\Throwable $e) {
        }
    }
}

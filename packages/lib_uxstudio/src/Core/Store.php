<?php

namespace Ux1\UxStudio\Core;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Generic key / value storage of a module (table #__uxstudio_store, one namespace per module), for small state that
 * does not deserve its own table: pairing data, last-run timestamps, cached remote answers, counters.
 *
 *   $store = $ctx->store();                       // or Store::forModule('my-module')
 *   $store->set('last_scan', time());
 *   $store->set('feed', $payload, 3600);          // expires after an hour
 *   $store->get('last_scan', 0);
 *   $store->increment('hits');                    // atomic counter
 *
 * Values are anything json_encode() accepts (scalars, arrays). Keys: 1-190 characters. An expired value behaves as missing
 * and is removed by the daily core.retention job. Do not keep secrets in it (use a password-type setting, which is encrypted).
 */
final class Store
{
    private const TABLE = '#__uxstudio_store';

    public function __construct(private DatabaseInterface $db, private string $moduleId)
    {
    }

    public static function forModule(string $moduleId): self
    {
        return new self(Kernel::instance()->db(), $moduleId);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->row($key);
        if ($row === null) {
            return $default;
        }
        $decoded = json_decode((string) $row['v'], true, 512, 0);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function has(string $key): bool
    {
        return $this->row($key) !== null;
    }

    /** @param int $ttl seconds until the value expires, 0 = never */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $key = $this->key($key);
        $this->write($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $ttl > 0 ? time() + $ttl : null);
    }

    public function delete(string $key): void
    {
        $key = $this->key($key);
        $m   = $this->moduleId;
        $db  = $this->db;
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('module') . ' = :m')->where($db->quoteName('k') . ' = :k')->bind(':m', $m)->bind(':k', $key)
        )->execute();
    }

    /**
     * Atomically adds $by to an integer value (missing or expired = 0) and returns the new value.
     *
     * @param int $ttl seconds until the counter expires, 0 = keep the current expiry (never for a new counter)
     */
    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $key = $this->key($key);
        $m   = $this->moduleId;
        $db  = $this->db;
        $now = time();
        $exp = $ttl > 0 ? $now + $ttl : null;

        // An expired counter starts again from zero.
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('module') . ' = :m')->where($db->quoteName('k') . ' = :k')
                ->where($db->quoteName('expires_at') . ' IS NOT NULL')->where($db->quoteName('expires_at') . ' <= :n')
                ->bind(':m', $m)->bind(':k', $key)->bind(':n', $now, ParameterType::INTEGER)
        )->execute();

        $initial = (string) $by;
        $query   = $db->createQuery()->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (`module`, `k`, `v`, `expires_at`, `updated_at`) VALUES (:m, :k, :v, :e, :u) '
            . 'ON DUPLICATE KEY UPDATE `v` = CAST(CAST(`v` AS SIGNED) + :by AS CHAR), `updated_at` = VALUES(`updated_at`)'
            . ($exp !== null ? ', `expires_at` = VALUES(`expires_at`)' : '')
        )->bind(':m', $m)->bind(':k', $key)->bind(':v', $initial)->bind(':e', $exp, $exp === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':u', $now, ParameterType::INTEGER)->bind(':by', $by, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        return (int) $this->get($key, 0);
    }

    /** @return string[] keys of the module that start with $prefix (not expired) */
    public function keys(string $prefix = ''): array
    {
        $m   = $this->moduleId;
        $db  = $this->db;
        $now = time();
        $q   = $db->createQuery()->select($db->quoteName('k'))->from($db->quoteName(self::TABLE))->where($db->quoteName('module') . ' = :m')
            ->where('(' . $db->quoteName('expires_at') . ' IS NULL OR ' . $db->quoteName('expires_at') . ' > :n)')
            ->bind(':m', $m)->bind(':n', $now, ParameterType::INTEGER)->order($db->quoteName('k'));
        if ($prefix !== '') {
            $like = addcslashes($prefix, '\\%_') . '%';
            $q->where($db->quoteName('k') . ' LIKE :p')->bind(':p', $like);
        }

        return array_map('strval', $db->setQuery($q)->loadColumn() ?: []);
    }

    /** Removes the keys that start with $prefix ('' = everything of the module). @return int number of removed values */
    public function deletePrefix(string $prefix = ''): int
    {
        $m  = $this->moduleId;
        $db = $this->db;
        $q  = $db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('module') . ' = :m')->bind(':m', $m);
        if ($prefix !== '') {
            $like = addcslashes($prefix, '\\%_') . '%';
            $q->where($db->quoteName('k') . ' LIKE :p')->bind(':p', $like);
        }
        $db->setQuery($q)->execute();

        return $db->getAffectedRows();
    }

    /** Removes expired values of all modules (called by the core.retention job). @return int number of removed values */
    public static function purgeExpired(): int
    {
        $db  = Kernel::instance()->db();
        $now = time();
        try {
            $db->setQuery(
                $db->createQuery()->delete($db->quoteName(self::TABLE))->where($db->quoteName('expires_at') . ' IS NOT NULL')->where($db->quoteName('expires_at') . ' <= :n')
                    ->bind(':n', $now, ParameterType::INTEGER)
            )->execute();

            return $db->getAffectedRows();
        } catch (\Throwable $e) {
            return -1;
        }
    }

    private function key(string $key): string
    {
        if ($key === '' || \strlen($key) > 190) {
            throw new \InvalidArgumentException('A store key must be 1-190 characters.');
        }

        return $key;
    }

    /** @return array{v:string}|null the live row */
    private function row(string $key): ?array
    {
        $key = $this->key($key);
        $m   = $this->moduleId;
        $db  = $this->db;
        $now = time();
        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName('v'))->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('module') . ' = :m')->where($db->quoteName('k') . ' = :k')
                ->where('(' . $db->quoteName('expires_at') . ' IS NULL OR ' . $db->quoteName('expires_at') . ' > :n)')
                ->bind(':m', $m)->bind(':k', $key)->bind(':n', $now, ParameterType::INTEGER)
        )->loadAssoc();

        return $row ?: null;
    }

    private function write(string $key, string $json, ?int $expires): void
    {
        $m   = $this->moduleId;
        $db  = $this->db;
        $now = time();
        $query = $db->createQuery()->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (`module`, `k`, `v`, `expires_at`, `updated_at`) VALUES (:m, :k, :v, :e, :u) '
            . 'ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `expires_at` = VALUES(`expires_at`), `updated_at` = VALUES(`updated_at`)'
        )->bind(':m', $m)->bind(':k', $key)->bind(':v', $json)->bind(':e', $expires, $expires === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':u', $now, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }
}

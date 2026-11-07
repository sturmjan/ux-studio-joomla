<?php

namespace Ux1\UxStudio\Modules\AutoImageUpload;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Map "external address -> local file" (so one address is downloaded once, however many articles use it) and the
 * short history of failures (a failing address is not retried on every save).
 */
final class Store
{
    public const TABLE = '#__uxstudio_auto_image_upload';

    /** A failed address is tried again after this many seconds. */
    public const RETRY_AFTER = 21600;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function get(string $url): ?array
    {
        $hash = sha1($url);
        $db   = $this->db;
        $row  = $db->setQuery($db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('url_hash') . ' = :h')->bind(':h', $hash))->loadAssoc();

        return $row ?: null;
    }

    public function saveOk(string $url, string $path, string $context, int $itemId): void
    {
        $this->upsert($url, 'ok', $path, '', $context, $itemId);
    }

    public function saveFailed(string $url, string $error, string $context, int $itemId): void
    {
        $this->upsert($url, 'failed', '', $error, $context, $itemId);
    }

    private function upsert(string $url, string $status, string $path, string $error, string $context, int $itemId): void
    {
        $db   = $this->db;
        $now  = Factory::getDate()->toSql();
        $hash = sha1($url);
        $shortUrl = mb_substr($url, 0, 1000);
        $error    = mb_substr($error, 0, 190);
        $context  = mb_substr($context, 0, 60);

        if ($this->get($url)) {
            $q = $db->createQuery()->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('status') . ' = :st')->set($db->quoteName('path') . ' = :p')->set($db->quoteName('error') . ' = :e')
                ->set($db->quoteName('context') . ' = :c')->set($db->quoteName('item_id') . ' = :i')->set($db->quoteName('updated') . ' = :u')
                ->where($db->quoteName('url_hash') . ' = :h')
                ->bind(':st', $status)->bind(':p', $path)->bind(':e', $error)->bind(':c', $context)->bind(':i', $itemId, ParameterType::INTEGER)->bind(':u', $now)->bind(':h', $hash);
            $db->setQuery($q)->execute();

            return;
        }
        $q = $db->createQuery()->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName(['url_hash', 'url', 'status', 'path', 'error', 'context', 'item_id', 'created', 'updated']))
            ->values(':h, :url, :st, :p, :e, :c, :i, :cr, :u')
            ->bind(':h', $hash)->bind(':url', $shortUrl)->bind(':st', $status)->bind(':p', $path)->bind(':e', $error)->bind(':c', $context)
            ->bind(':i', $itemId, ParameterType::INTEGER)->bind(':cr', $now)->bind(':u', $now);
        $db->setQuery($q)->execute();
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function page(int $page, int $per, string $status): array
    {
        $db    = $this->db;
        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName(self::TABLE));
        $list  = $db->createQuery()->select('*')->from($db->quoteName(self::TABLE))->order($db->quoteName('updated') . ' DESC, ' . $db->quoteName('id') . ' DESC');
        if (\in_array($status, ['ok', 'failed'], true)) {
            foreach ([$count, $list] as $q) {
                $q->where($db->quoteName('status') . ' = :st')->bind(':st', $status);
            }
        }

        return [
            'items' => $db->setQuery($list, max(0, ($page - 1) * $per), $per)->loadAssocList() ?: [],
            'total' => (int) $db->setQuery($count)->loadResult(),
        ];
    }

    /** @return array{ok:int,failed:int} */
    public function counts(): array
    {
        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select([$db->quoteName('status'), 'COUNT(*) AS n'])->from($db->quoteName(self::TABLE))->group($db->quoteName('status')))->loadAssocList('status', 'n') ?: [];

        return ['ok' => (int) ($rows['ok'] ?? 0), 'failed' => (int) ($rows['failed'] ?? 0)];
    }
}

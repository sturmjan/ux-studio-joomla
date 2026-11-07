<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/** Access log of known AI bots on the markdown endpoints (#__uxstudio_ai_markdown_log). */
final class AccessLog
{
    private const MAX_LIMIT = 200;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /** Writes one row when the user agent is a known bot; never throws (logging must not break the response). */
    public function record(string $kind, int $refId, string $url, string $via): void
    {
        try {
            $ua  = substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 255);
            $bot = BotDetector::detect($ua);
            if ($bot === null) {
                return;
            }
            // The user agent is spoofable: bound the rows one client can write (over the limit the hit is simply not logged).
            if ((new RateLimiter($this->db))->hitClient('ai-markdown:log', 60, 60) > 0) {
                return;
            }
            $ip  = ClientIp::current();
            $ip  = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
            $now = Factory::getDate()->toSql();
            $url = substr($url, 0, 2048);
            $this->db->setQuery(
                $this->db->createQuery()->insert($this->db->quoteName('#__uxstudio_ai_markdown_log'))
                    ->columns($this->db->quoteName(['created', 'kind', 'ref_id', 'bot_name', 'url', 'via', 'ip_address', 'user_agent']))
                    ->values(':c, :k, :r, :b, :u, :v, :i, :ua')
                    ->bind(':c', $now)->bind(':k', $kind)->bind(':r', $refId, ParameterType::INTEGER)->bind(':b', $bot)
                    ->bind(':u', $url)->bind(':v', $via)->bind(':i', $ip)->bind(':ua', $ua)
            )->execute();
        } catch (\Throwable) {
            // Ignore.
        }
    }

    /**
     * @param array{search?:string,bot?:string,kind?:string} $f
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function list(array $f, int $page, int $perPage): array
    {
        $db      = $this->db;
        $perPage = max(1, min(self::MAX_LIMIT, $perPage));
        $page    = max(1, $page);
        $where   = function ($q) use ($db, $f): void {
            if (($f['bot'] ?? '') !== '') {
                $bot = (string) $f['bot'];
                $q->where($db->quoteName('bot_name') . ' = :bot')->bind(':bot', $bot);
            }
            if (($f['kind'] ?? '') !== '') {
                $kind = (string) $f['kind'];
                $q->where($db->quoteName('kind') . ' = :kind')->bind(':kind', $kind);
            }
            if (($f['search'] ?? '') !== '') {
                $like = '%' . addcslashes((string) $f['search'], '\\%_') . '%';
                $q->where('(' . $db->quoteName('bot_name') . ' LIKE :s1 OR ' . $db->quoteName('url') . ' LIKE :s2 OR ' . $db->quoteName('ip_address') . ' LIKE :s3 OR ' . $db->quoteName('user_agent') . ' LIKE :s4)')
                    ->bind(':s1', $like)->bind(':s2', $like)->bind(':s3', $like)->bind(':s4', $like);
            }
        };
        $cq = $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_ai_markdown_log'));
        $where($cq);
        $total = (int) $db->setQuery($cq)->loadResult();

        $q = $db->createQuery()->select('*')->from($db->quoteName('#__uxstudio_ai_markdown_log'))->order('created DESC, id DESC');
        $where($q);
        $rows  = $db->setQuery($q, ($page - 1) * $perPage, $perPage)->loadAssocList() ?: [];
        $items = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'created'    => (string) $r['created'],
            'kind'       => (string) $r['kind'],
            'ref_id'     => (int) $r['ref_id'],
            'bot_name'   => (string) $r['bot_name'],
            'url'        => (string) $r['url'],
            'via'        => (string) $r['via'],
            'ip_address' => (string) $r['ip_address'],
            'user_agent' => (string) $r['user_agent'],
        ], $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array{total:int,last_24h:int,bots:array<int,array{bot_name:string,count:int}>} */
    public function stats(): array
    {
        $db    = $this->db;
        $since = Factory::getDate('-1 day')->toSql();
        $total = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_ai_markdown_log')))->loadResult();
        $day   = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_ai_markdown_log'))
            ->where($db->quoteName('created') . ' >= :s')->bind(':s', $since))->loadResult();
        $rows  = $db->setQuery($db->createQuery()->select('bot_name, COUNT(*) AS cnt')->from($db->quoteName('#__uxstudio_ai_markdown_log'))
            ->group('bot_name')->order('cnt DESC'), 0, 50)->loadAssocList() ?: [];

        return [
            'total'    => $total,
            'last_24h' => $day,
            'bots'     => array_map(static fn (array $r): array => ['bot_name' => (string) $r['bot_name'], 'count' => (int) $r['cnt']], $rows),
        ];
    }

    /** @param int[] $ids */
    public function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if (!$ids) {
            return 0;
        }
        $this->db->setQuery($this->db->createQuery()->delete($this->db->quoteName('#__uxstudio_ai_markdown_log'))->whereIn($this->db->quoteName('id'), $ids))->execute();

        return $this->db->getAffectedRows();
    }

    public function clear(): int
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__uxstudio_ai_markdown_log'))->execute();

        return $this->db->getAffectedRows();
    }
}

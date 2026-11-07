<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Generated documents (one article, one category, llms.txt, llms-full.txt, ai-sitemap.md) in #__uxstudio_ai_markdown_cache.
 * A row is valid while its hash matches the current source and, when a TTL is set, while it is younger than the TTL.
 */
final class Cache
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /** @return array{content:string,generated_at:string,content_hash:string}|null */
    public function get(string $key, string $hash, int $ttlHours): ?array
    {
        $row = $this->db->setQuery(
            $this->db->createQuery()->select($this->db->quoteName(['content', 'generated_at', 'content_hash']))
                ->from($this->db->quoteName('#__uxstudio_ai_markdown_cache'))
                ->where($this->db->quoteName('cache_key') . ' = :k')->bind(':k', $key)
        )->loadAssoc();
        if (!$row || !hash_equals((string) $row['content_hash'], $hash)) {
            return null;
        }
        if ($ttlHours > 0) {
            $ts = strtotime($row['generated_at'] . ' UTC');
            if (!$ts || time() - $ts > $ttlHours * 3600) {
                return null;
            }
        }

        return $row;
    }

    public function put(string $key, string $kind, int $refId, string $hash, string $content): string
    {
        $now = Factory::getDate()->toSql();
        $this->db->setQuery(
            $this->db->createQuery()->delete($this->db->quoteName('#__uxstudio_ai_markdown_cache'))
                ->where($this->db->quoteName('cache_key') . ' = :k')->bind(':k', $key)
        )->execute();
        $this->db->setQuery(
            $this->db->createQuery()->insert($this->db->quoteName('#__uxstudio_ai_markdown_cache'))
                ->columns($this->db->quoteName(['cache_key', 'kind', 'ref_id', 'content_hash', 'content', 'generated_at']))
                ->values(':k, :kind, :ref, :h, :c, :t')
                ->bind(':k', $key)->bind(':kind', $kind)->bind(':ref', $refId, ParameterType::INTEGER)->bind(':h', $hash)->bind(':c', $content)->bind(':t', $now)
        )->execute();

        return $now;
    }

    /** Drops cached rows: one key, one article, every aggregate or everything. */
    public function forget(?string $key = null, bool $aggregatesOnly = false): void
    {
        $q = $this->db->createQuery()->delete($this->db->quoteName('#__uxstudio_ai_markdown_cache'));
        if ($key !== null) {
            $q->where($this->db->quoteName('cache_key') . ' = :k')->bind(':k', $key);
        } elseif ($aggregatesOnly) {
            $q->where($this->db->quoteName('kind') . ' <> ' . $this->db->quote('article'));
        }
        $this->db->setQuery($q)->execute();
    }

    public function count(): int
    {
        return (int) $this->db->setQuery($this->db->createQuery()->select('COUNT(*)')->from($this->db->quoteName('#__uxstudio_ai_markdown_cache'))
            ->where($this->db->quoteName('kind') . ' = ' . $this->db->quote('article')))->loadResult();
    }

    /** Removes cached articles that are gone or no longer public (ids not in the list). */
    public function pruneArticles(array $keepIds): void
    {
        $q = $this->db->createQuery()->delete($this->db->quoteName('#__uxstudio_ai_markdown_cache'))
            ->where($this->db->quoteName('kind') . ' = ' . $this->db->quote('article'));
        if ($keepIds) {
            $q->whereNotIn($this->db->quoteName('ref_id'), array_map('intval', $keepIds));
        }
        $this->db->setQuery($q)->execute();
    }
}

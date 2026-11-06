<?php

namespace Ux1\UxStudio\Core;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Fixed-window rate limiter backed by #__uxstudio_ratelimit (one row per hashed bucket, atomic upsert).
 * Modules can use it for their own write endpoints: (new RateLimiter($ctx->db()))->hit('my-module:contact:' . $ip, 5, 600).
 */
final class RateLimiter
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Counts one request in the bucket.
     *
     * @return int 0 when the request is allowed, otherwise the number of seconds until the window resets
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): int
    {
        $db     = $this->db;
        $key    = sha1($bucket);
        $now    = time();
        $window = max(1, $windowSeconds);
        $cutoff = $now - $window;

        // All interpolated values are a sha1 hex string (quoted) or integers computed here.
        // Left-to-right evaluation: "hits" sees the OLD window_start, then window_start is moved.
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__uxstudio_ratelimit') . ' (' . $db->quoteName('bucket') . ', ' . $db->quoteName('hits') . ', ' . $db->quoteName('window_start') . ') '
            . 'VALUES (' . $db->quote($key) . ', 1, ' . (int) $now . ') ON DUPLICATE KEY UPDATE '
            . $db->quoteName('hits') . ' = IF(' . $db->quoteName('window_start') . ' <= ' . (int) $cutoff . ', 1, ' . $db->quoteName('hits') . ' + 1), '
            . $db->quoteName('window_start') . ' = IF(' . $db->quoteName('window_start') . ' <= ' . (int) $cutoff . ', ' . (int) $now . ', ' . $db->quoteName('window_start') . ')'
        )->execute();

        $row = $db->setQuery(
            $db->createQuery()->select($db->quoteName(['hits', 'window_start']))->from($db->quoteName('#__uxstudio_ratelimit'))
                ->where($db->quoteName('bucket') . ' = :b')->bind(':b', $key)
        )->loadAssoc();

        // Opportunistic cleanup of long expired buckets (about 1 request in 100).
        if (random_int(1, 100) === 1) {
            $old = $now - 86400;
            $db->setQuery(
                $db->createQuery()->delete($db->quoteName('#__uxstudio_ratelimit'))->where($db->quoteName('window_start') . ' < :o')->bind(':o', $old, ParameterType::INTEGER)
            )->execute();
        }

        if (!$row || (int) $row['hits'] <= $limit) {
            return 0;
        }

        return max(1, (int) $row['window_start'] + $window - $now);
    }
}

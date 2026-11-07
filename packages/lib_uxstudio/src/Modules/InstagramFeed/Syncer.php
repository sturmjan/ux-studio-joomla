<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * One synchronisation run: profile + latest posts from a MediaSource into the database cache, local image copies,
 * removal of posts that were deleted on Instagram.
 */
final class Syncer
{
    /** Images downloaded in one run (the rest is picked up by the next run), keeps the scheduler job short. */
    private const MAX_DOWNLOADS = 40;

    /** Failed downloads of one post after which it is skipped. */
    private const MAX_FAILS = 3;

    public function __construct(private MediaStore $store, private ImageCache $images)
    {
    }

    /**
     * @return array{fetched:int,new:int,updated:int,images:int,removed:int,skipped:int}
     * @throws \Throwable what the source throws (the previous state of the cache stays untouched then)
     */
    public function run(MediaSource $source, int $limit, bool $storeImages): array
    {
        $limit = max(1, min(100, $limit));
        $stats = ['fetched' => 0, 'new' => 0, 'updated' => 0, 'images' => 0, 'removed' => 0, 'skipped' => 0];

        $raw = $source->media($limit);
        try {
            $profile = $source->profile(true);
        } catch (\Throwable $e) {
            $profile = [];
        }
        if ($profile) {
            $this->store->setMeta('profile', (string) json_encode(self::cleanProfile($profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $seen  = [];
        $times = [];
        foreach ($raw as $item) {
            $row = Item::normalize($item);
            if ($row === null) {
                ++$stats['skipped'];
                continue;
            }
            ++$stats['fetched'];
            $seen[$row['instagram_id']] = true;
            if ($row['media_timestamp'] !== null) {
                $times[] = $row['media_timestamp'];
            }
            ++$stats[$this->store->upsert($row) === 'new' ? 'new' : 'updated'];
        }

        // Posts deleted on Instagram: everything inside the fetched window that was not returned. A window that is not
        // full (fewer posts than asked for) is the whole account. An empty answer never deletes anything (safer than
        // wiping the cache because of a hiccup).
        if ($stats['fetched'] > 0) {
            $complete = \count($raw) < $limit;
            $oldest   = $times ? min($times) : null;
            $gone     = [];
            $files    = [];
            foreach ($this->store->index() as $cached) {
                if (isset($seen[$cached['instagram_id']])) {
                    continue;
                }
                if ($complete || ($oldest !== null && $cached['media_timestamp'] !== null && $cached['media_timestamp'] >= $oldest)) {
                    $gone[]  = $cached['id'];
                    $files[] = $cached['local_file'];
                }
            }
            if ($gone) {
                $this->store->delete($gone);
                foreach ($files as $file) {
                    ImageCache::remove($file);
                }
                $stats['removed'] = \count($gone);
            }
        }

        if ($storeImages) {
            $stats['images'] = $this->downloadMissing();
        }

        return $stats;
    }

    /** Stores the images that have no local copy yet. @return int number of images downloaded */
    public function downloadMissing(): int
    {
        // Links that failed repeatedly (not an image, 403, expired) are skipped, but get a fresh chance once a week.
        $reset = (int) $this->store->getMeta('dl_reset_at');
        if (time() - $reset > 7 * 86400) {
            $this->store->resetDownloadFailures();
            $this->store->setMeta('dl_reset_at', (string) time());
        }

        $done = 0;
        foreach ($this->store->withoutLocalFile(self::MAX_DOWNLOADS, self::MAX_FAILS) as $row) {
            $url = Item::imageUrl($row);
            $file = $url === '' ? '' : $this->images->store($row['instagram_id'], $url);
            if ($file !== '') {
                $this->store->setLocalFile($row['id'], $file);
                ++$done;
            } else {
                $this->store->markDownloadFailed($row['id']);
            }
        }

        return $done;
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function cleanProfile(array $p): array
    {
        $num = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return [
            'username'        => mb_substr(preg_replace('/[^A-Za-z0-9._]/', '', (string) ($p['username'] ?? '')) ?? '', 0, 60),
            'name'            => mb_substr(Item::text($p['name'] ?? ''), 0, 120),
            'account_type'    => mb_substr(preg_replace('/[^A-Za-z_]/', '', (string) ($p['account_type'] ?? '')) ?? '', 0, 30),
            'followers_count' => $num($p['followers_count'] ?? null),
            'media_count'     => $num($p['media_count'] ?? null),
            'synced_at'       => Factory::getDate()->toSql(),
        ];
    }
}

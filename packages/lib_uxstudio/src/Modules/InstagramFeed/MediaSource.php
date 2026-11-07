<?php

namespace Ux1\UxStudio\Modules\InstagramFeed;

\defined('_JEXEC') or die;

/** Where the posts come from (the central app in production, a fake in tests). */
interface MediaSource
{
    /**
     * Profile of the connected account.
     *
     * @return array<string,mixed> username, account_type, name, profile_picture_url, followers_count, media_count ...
     */
    public function profile(bool $fresh = false): array;

    /**
     * Latest posts, newest first, raw Graph API items.
     *
     * @return array<int,array<string,mixed>>
     */
    public function media(int $limit): array;
}

<?php

namespace App\Observers;

use App\Models\StoryAuthor;
use App\Services\Post\AnnouncementPostService;

/**
 * Owner identity changes can turn an account-safe story announcement into a
 * Separate-persona ownership leak, so synchronize at that narrow boundary.
 */
class StoryAuthorAnnouncementObserver
{
    public function __construct(private readonly AnnouncementPostService $announcements) {}

    public function saved(StoryAuthor $author): void
    {
        if ($author->isOwner() && $author->story !== null) {
            $this->announcements->synchronize($author->story);
        }
    }
}

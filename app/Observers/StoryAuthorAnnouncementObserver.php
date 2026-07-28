<?php

namespace App\Observers;

use App\Models\StoryAuthor;
use App\Services\Post\AnnouncementPostService;

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

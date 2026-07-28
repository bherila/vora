<?php

namespace App\Observers;

use App\Models\Media;
use App\Models\Story;
use App\Services\Post\AnnouncementPostService;

class ContentAnnouncementObserver
{
    public function __construct(private readonly AnnouncementPostService $announcements) {}

    /**
     * Approval, publication, privacy, and media-persona changes all pass through
     * save(), making this the single model-level synchronization boundary.
     */
    public function saved(Media|Story $content): void
    {
        $this->announcements->synchronize($content);
    }

    public function deleted(Media|Story $content): void
    {
        $this->announcements->synchronize($content);
    }

    public function restored(Media|Story $content): void
    {
        $this->announcements->synchronize($content);
    }
}

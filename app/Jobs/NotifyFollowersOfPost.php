<?php

namespace App\Jobs;

use App\Models\FollowRequest;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FollowedUserPosted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans out a new-post notification to the author's active followers who can view
 * the post and have not opted out. Queued so a popular account's fan-out does
 * not run on the post-creation request path; chunked so memory stays bounded.
 */
class NotifyFollowersOfPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly Post $post) {}

    public function handle(): void
    {
        $author = $this->post->user;
        if ($author === null) {
            return;
        }

        $followerIds = FollowRequest::query()
            ->where('recipient_id', $author->id)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->select('requester_id');

        User::query()
            ->whereIn('id', $followerIds)
            ->active()
            ->where('notify_new_post', true)
            ->chunkById(200, function ($followers): void {
                foreach ($followers as $follower) {
                    if ($this->post->isViewableBy($follower)) {
                        $follower->notify(new FollowedUserPosted($this->post));
                    }
                }
            });
    }
}

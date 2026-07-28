<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Notifications\FollowedUserPosted;
use App\Services\Post\AnnouncementPostService;
use App\Support\FollowGraph;
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

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly Post $post) {}

    public function handle(): void
    {
        $post = Post::withTrashed()->find($this->post->id);
        if (! $post instanceof Post || $post->trashed() || ! $post->isApprovedContent()) {
            return;
        }

        if ($post->is_announcement && ! $this->hasPublishableAnnouncementAttachment($post)) {
            return;
        }

        $author = $post->user;
        if ($author === null || ! $author->isActive()) {
            return;
        }

        $followerIds = FollowGraph::followersOfIdentity($author->id, $post->character_id)
            ->where(function ($query) use ($post): void {
                $query
                    ->whereNull('responded_at')
                    ->orWhere('responded_at', '<=', $post->created_at);
            })
            ->select('requester_id');

        User::query()
            ->whereIn('id', $followerIds)
            ->active()
            ->where('notify_new_post', true)
            ->chunkById(200, function ($followers) use ($post): void {
                foreach ($followers as $follower) {
                    // The edge query scopes membership; the full post gate still
                    // enforces its audience and any future restrictions.
                    if ($post->isViewableBy($follower)) {
                        $follower->notify(new FollowedUserPosted($post));
                    }
                }
            });
    }

    private function hasPublishableAnnouncementAttachment(Post $post): bool
    {
        $content = $post->attachments()->with('attachable')->first()?->attachable;
        if (! $content instanceof Media && ! $content instanceof Story) {
            return false;
        }

        return resolve(AnnouncementPostService::class)->isPublishable($content);
    }
}

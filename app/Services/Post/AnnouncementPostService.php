<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\Story;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and synchronizes the feed post that announces reviewed content.
 *
 * The attached item owns the privacy policy. The post stores a literal copy so
 * the hot feed query can continue using Post::scopeViewableBy() without a
 * polymorphic privacy join.
 */
class AnnouncementPostService
{
    public function synchronize(Model $content): void
    {
        if (! $content instanceof Media && ! $content instanceof Story) {
            return;
        }

        $post = DB::transaction(function () use ($content): ?Post {
            // Serialize approval/publication races on the content itself, then
            // recheck attachments only after acquiring that lock.
            $locked = $content::withTrashed()
                ->whereKey($content->getKey())
                ->lockForUpdate()
                ->first() ?? $content;

            $attachment = $this->announcementAttachmentFor($locked);
            if ($attachment !== null) {
                $existing = Post::withTrashed()->lockForUpdate()->find($attachment->post_id);
                if ($existing !== null && ! $existing->trashed()) {
                    if ($this->isReadyToAnnounce($locked)) {
                        $this->copyPrivacy($existing, $locked);
                    } else {
                        $this->hide($existing);
                    }
                }

                return null;
            }

            // A user already shared this item manually. That is its one feed
            // announcement; approving it must not add a duplicate post.
            if ($this->anyAttachmentFor($locked) !== null || ! $this->isReadyToAnnounce($locked)) {
                return null;
            }

            $post = new Post([
                'user_id' => $locked->user_id,
                'character_id' => $this->characterId($locked),
                'ulid' => (string) Str::ulid(),
                'body' => $locked instanceof Media ? 'Shared new media.' : 'Published a new story.',
                'audience' => $locked->audience,
                'discoverable' => (bool) $locked->discoverable,
                'is_announcement' => true,
            ]);
            $post->moderation_status = ModerationStatus::Approved;
            $post->save();
            $post->attachments()->create([
                'attachable_type' => $locked->getMorphClass(),
                'attachable_id' => $locked->getKey(),
            ]);
            $this->copyPrivacy($post, $locked);

            return $post;
        });

        if ($post !== null) {
            NotifyFollowersOfPost::dispatch($post)->afterCommit();
        }
    }

    private function isReadyToAnnounce(Media|Story $content): bool
    {
        if ($content->trashed()
            || ! (bool) $content->announce_on_approval
            || ! $content->isApprovedContent()) {
            return false;
        }

        if ($content instanceof Media) {
            return $content->purpose === MediaPurpose::Gallery && $content->isReady();
        }

        return $content->status === StoryStatus::Published;
    }

    private function copyPrivacy(Post $post, Media|Story $content): void
    {
        $post->forceFill([
            'audience' => $content->audience,
            'discoverable' => (bool) $content->discoverable,
            'character_id' => $this->characterId($content),
            'moderation_status' => ModerationStatus::Approved,
        ])->save();

        $post->syncAudienceMembers(
            $content->audience === Audience::SpecificPeople
                ? $content->audienceMembers()->pluck('user_id')->map('intval')->all()
                : [],
        );
    }

    private function characterId(Media|Story $content): ?int
    {
        if ($content instanceof Media) {
            return $content->character_id !== null ? (int) $content->character_id : null;
        }

        // Story privacy has no persona context in HasPrivacyPolicy. A persona
        // byline here would apply Followers/Mutuals to a different follow scope
        // than the attached story, so stories announce as their owning account.
        return null;
    }

    private function hide(Post $post): void
    {
        if (! $post->isPendingReview()) {
            $post->forceFill(['moderation_status' => ModerationStatus::Pending])->save();
        }
    }

    private function announcementAttachmentFor(Media|Story $content): ?PostAttachment
    {
        return PostAttachment::query()
            ->where('attachable_type', $content->getMorphClass())
            ->where('attachable_id', $content->getKey())
            ->whereHas('post', fn ($query) => $query->where('is_announcement', true))
            ->oldest('id')
            ->first();
    }

    private function anyAttachmentFor(Media|Story $content): ?PostAttachment
    {
        return PostAttachment::query()
            ->where('attachable_type', $content->getMorphClass())
            ->where('attachable_id', $content->getKey())
            ->oldest('id')
            ->first();
    }
}

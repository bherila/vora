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
use App\Models\StoryAuthor;
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

        $attachment = $this->announcementAttachmentFor($content);
        if ($attachment !== null) {
            $post = Post::withTrashed()->find($attachment->post_id);
            if ($post !== null && ! $post->trashed()) {
                $this->copyPrivacy($post, $content);
            }

            return;
        }

        // A user already shared this item manually. That is its one feed
        // announcement; approving it must not add a duplicate post.
        if ($this->anyAttachmentFor($content) !== null) {
            return;
        }

        if (! $this->isReadyToAnnounce($content)) {
            return;
        }

        $post = DB::transaction(function () use ($content): ?Post {
            // Recheck inside the write transaction so repeated approvals remain
            // idempotent.
            if ($this->anyAttachmentFor($content) !== null) {
                return null;
            }

            $post = new Post([
                'user_id' => $content->user_id,
                'character_id' => $this->characterId($content),
                'ulid' => (string) Str::ulid(),
                'body' => $content instanceof Media ? 'Shared new media.' : 'Published a new story.',
                'audience' => $content->audience,
                'discoverable' => (bool) $content->discoverable,
                'is_announcement' => true,
            ]);
            $post->moderation_status = ModerationStatus::Approved;
            $post->save();
            $post->attachments()->create([
                'attachable_type' => $content->getMorphClass(),
                'attachable_id' => $content->getKey(),
            ]);
            $this->copyPrivacy($post, $content);

            return $post;
        });

        if ($post !== null) {
            NotifyFollowersOfPost::dispatch($post);
        }
    }

    private function isReadyToAnnounce(Media|Story $content): bool
    {
        if (! (bool) $content->announce_on_approval || ! $content->isApprovedContent()) {
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

        $characterId = $content->authors()
            ->where('user_id', $content->user_id)
            ->where('role', StoryAuthor::ROLE_OWNER)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->value('character_id');

        return $characterId !== null ? (int) $characterId : null;
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

<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
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

        $post = DB::transaction(function () use ($content): ?Post {
            // Serialize approval/publication races on the content itself.
            $locked = $content::withTrashed()
                ->whereKey($content->getKey())
                ->lockForUpdate()
                ->first() ?? $content;

            if ($locked->canonical_post_id !== null) {
                $existing = Post::withTrashed()->lockForUpdate()->find($locked->canonical_post_id);
                // Only posts this system created mirror the content's privacy.
                // A user-authored post that merely claimed the canonical slot
                // keeps the audience its author chose; PostService already
                // clamped it against the attachment.
                $systemOwned = $existing !== null && ($existing->is_announcement || $existing->is_feed_hidden);
                if ($existing !== null && ! $existing->trashed() && $systemOwned) {
                    $available = $existing->is_announcement
                        ? $this->isPublishable($locked)
                        : $this->isDiscussionAvailable($locked);
                    if ($available) {
                        $this->copyPrivacy($existing, $locked);
                    } else {
                        $this->hide($existing);
                    }
                }

                if ($existing?->trashed()) {
                    $locked->forceFill(['canonical_post_id' => null])->save();
                } else {
                    return null;
                }
            }

            // A user already shared this item manually. That is its one feed
            // announcement; approving it must not add a duplicate post.
            if (! $this->isPublishable($locked)) {
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
                'position' => 0,
            ]);
            $locked->forceFill(['canonical_post_id' => $post->id])->save();
            $this->copyPrivacy($post, $locked);

            return $post;
        });

        if ($post !== null) {
            NotifyFollowersOfPost::dispatch($post)->afterCommit();
        }
    }

    /**
     * The common last-moment gate used by creation, synchronization, and queued
     * notification fan-out.
     */
    public function isPublishable(Media|Story $content): bool
    {
        if ($content->trashed()
            || ! (bool) $content->announce_on_approval
            || ! $content->isApprovedContent()) {
            return false;
        }

        if ($content instanceof Media) {
            return $content->purpose === MediaPurpose::Gallery && $content->isReady();
        }

        return $content->status === StoryStatus::Published
            && ! $this->ownerUsesSeparatePersona($content);
    }

    private function isDiscussionAvailable(Media|Story $content): bool
    {
        if ($content->trashed() || ! $content->isApprovedContent()) {
            return false;
        }

        return $content instanceof Media
            ? $content->purpose === MediaPurpose::Gallery && $content->isReady()
            : $content->status === StoryStatus::Published;
    }

    public function copyPrivacy(Post $post, Media|Story $content): void
    {
        $attributes = [
            'audience' => $content->audience,
            'discoverable' => (bool) $content->discoverable,
            'character_id' => $this->characterId($content),
        ];
        if (! $post->isRejected()) {
            $attributes['moderation_status'] = ModerationStatus::Approved;
        }
        $post->forceFill($attributes)->save();

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
        if (! $post->isPendingReview() && ! $post->isRejected()) {
            $post->forceFill(['moderation_status' => ModerationStatus::Pending])->save();
        }
    }

    /**
     * Story privacy is account-scoped, but a Separate owner persona deliberately
     * hides that account in public presentation. Until posts can carry distinct
     * byline and privacy identities, announcing it as the account would leak the
     * private owner relationship.
     */
    private function ownerUsesSeparatePersona(Story $story): bool
    {
        $ownerAuthor = $story->authors()
            ->where('user_id', $story->user_id)
            ->where('role', StoryAuthor::ROLE_OWNER)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->first();

        if (! $ownerAuthor instanceof StoryAuthor) {
            return true;
        }

        if ($ownerAuthor->character_id === null) {
            return false;
        }

        $character = Character::withTrashed()->find($ownerAuthor->character_id);

        return $character === null || ! $character->is_linked;
    }
}

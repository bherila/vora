<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Notifications\FollowedUserPosted;
use App\Services\Privacy\PrivacyAuditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates posts and their attachments, enforcing the ownership rule in one
 * place: a post may only attach a Character, Media, or Story the author owns.
 * Interest is a shared tag and needs no ownership check.
 */
class PostService
{
    /**
     * API attachment type keys → model class. Public so the Form Request can
     * validate against the same set.
     *
     * @var array<string, class-string<Model>>
     */
    public const ATTACHMENT_TYPES = [
        'character' => Character::class,
        'interest' => Interest::class,
        'media' => Media::class,
        'story' => Story::class,
    ];

    /** Attachment types the author must own. */
    private const OWNED_TYPES = ['character', 'media', 'story'];

    public function __construct(private readonly PrivacyAuditor $auditor) {}

    /**
     * @param  list<array{type: string, id: int}>  $attachments
     * @param  list<int>  $audienceUserIds
     */
    public function create(
        User $author,
        string $body,
        Audience $audience,
        bool $discoverable,
        array $attachments,
        array $audienceUserIds,
        Request $request,
    ): Post {
        $resolved = $this->resolveAttachments($author, $attachments);

        $post = $author->posts()->make([
            'ulid' => (string) Str::ulid(),
            'body' => $body,
            'audience' => $audience->value,
            'discoverable' => $discoverable,
        ]);
        // Short posts publish immediately and are moderated reactively (an admin
        // can reject/take one down), rather than sitting in a pre-publication
        // queue that would make the feed dead on arrival. Heavier content
        // (Media/Story) keeps pre-moderation. Set directly: the moderation column
        // is intentionally not mass-assignable.
        $post->moderation_status = ModerationStatus::Approved;
        $post->save();

        foreach ($resolved as $model) {
            $post->attachments()->create([
                'attachable_type' => $model->getMorphClass(),
                'attachable_id' => $model->getKey(),
            ]);
        }

        $post->syncAudienceMembers($audience === Audience::SpecificPeople ? $audienceUserIds : []);
        $this->auditor->recordCreation($post, $author, $post->privacySnapshot(), $request);

        $this->notifyFollowers($author, $post);

        return $post;
    }

    /**
     * Notify the author's active followers who are allowed to see the new post.
     * Chunked so a popular account's fan-out stays bounded in memory rather than
     * loading the whole follower set on the request path. (At larger scale this
     * fan-out should move to a queued job — tracked as a follow-up.)
     */
    private function notifyFollowers(User $author, Post $post): void
    {
        $followerIds = FollowRequest::query()
            ->where('recipient_id', $author->id)
            ->where('status', FollowRequest::STATUS_ACCEPTED)
            ->select('requester_id');

        User::query()
            ->whereIn('id', $followerIds)
            ->active()
            ->chunkById(200, function ($followers) use ($post): void {
                foreach ($followers as $follower) {
                    if ($post->isViewableBy($follower)) {
                        $follower->notify(new FollowedUserPosted($post));
                    }
                }
            });
    }

    /**
     * Resolve and authorize each attachment, de-duplicating repeats. Throws a
     * validation error if an item is unknown, missing, or not owned by the author.
     *
     * @param  list<array{type: string, id: int}>  $attachments
     * @return list<Model>
     */
    private function resolveAttachments(User $author, array $attachments): array
    {
        $resolved = [];
        $seen = [];

        foreach ($attachments as $i => $attachment) {
            $type = $attachment['type'];
            $id = (int) $attachment['id'];
            $class = self::ATTACHMENT_TYPES[$type] ?? null;

            if ($class === null) {
                throw ValidationException::withMessages(["attachments.$i.type" => 'Unknown attachment type.']);
            }

            $key = $type.':'.$id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $model = $class::query()->find($id);
            if ($model === null) {
                throw ValidationException::withMessages(["attachments.$i.id" => 'That attachment does not exist.']);
            }

            if (in_array($type, self::OWNED_TYPES, true) && $model->user_id !== $author->id) {
                throw ValidationException::withMessages([
                    "attachments.$i.id" => 'You can only attach your own content.',
                ]);
            }

            // Only gallery media is shareable/openable through the media surface;
            // attaching a profile picture would yield a ULID that can't be opened.
            if ($model instanceof Media && ! $model->isGalleryMedia()) {
                throw ValidationException::withMessages([
                    "attachments.$i.id" => 'Only gallery media can be attached.',
                ]);
            }

            $resolved[] = $model;
        }

        return $resolved;
    }
}

<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Privacy\PrivacyAuditor;
use App\Support\FollowGraph;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ?int $characterId = null,
    ): Post {
        return DB::transaction(function () use (
            $author,
            $body,
            $audience,
            $discoverable,
            $attachments,
            $audienceUserIds,
            $request,
            $characterId,
        ): Post {
            $resolved = $this->resolveAttachments($author, $attachments);
            $character = $this->resolveCharacter($author, $characterId);
            $this->assertRelationshipIdentitiesMatch($character?->id, $resolved);
            $privacy = $this->clampPrivacy(
                $author,
                $character?->id,
                $audience,
                $discoverable,
                $audienceUserIds,
                $resolved,
            );

            $post = $author->posts()->make([
                'ulid' => (string) Str::ulid(),
                'body' => $body,
                'audience' => $privacy['audience']->value,
                'discoverable' => $privacy['discoverable'],
                'character_id' => $character?->id,
            ]);
            // Short posts publish immediately and are moderated reactively (an
            // admin can reject/take one down), rather than sitting in a
            // pre-publication queue that would make the feed dead on arrival.
            $post->moderation_status = ModerationStatus::Approved;
            $post->save();

            foreach ($resolved as $model) {
                $post->attachments()->create([
                    'attachable_type' => $model->getMorphClass(),
                    'attachable_id' => $model->getKey(),
                ]);
            }

            $post->syncAudienceMembers($privacy['member_ids']);
            $this->auditor->recordCreation($post, $author, $post->privacySnapshot(), $request);

            // Never expose a partially-created aggregate to a queue worker.
            NotifyFollowersOfPost::dispatch($post)->afterCommit();

            return $post;
        });
    }

    /**
     * A post is the intersection of its requested policy and every attached
     * privacy policy. SpecificPeople is not ordered against relationship tiers:
     * a grant can outlive the relationship that existed when it was selected.
     * When either side is SpecificPeople, encode the exact write-time
     * intersection as a SpecificPeople allowlist rather than assuming an order
     * that could widen the post.
     *
     * @param  list<int>  $audienceUserIds
     * @param  list<Model>  $attachments
     * @return array{audience: Audience, discoverable: bool, member_ids: list<int>}
     */
    private function clampPrivacy(
        User $author,
        ?int $characterId,
        Audience $audience,
        bool $discoverable,
        array $audienceUserIds,
        array $attachments,
    ): array {
        $policies = [];
        $specificLists = [];
        if ($audience === Audience::SpecificPeople) {
            $specificLists[] = array_values(array_unique(array_map('intval', $audienceUserIds)));
        }

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Character
                && ! $attachment instanceof Media
                && ! $attachment instanceof Story) {
                continue;
            }

            $discoverable = $discoverable && (bool) $attachment->discoverable;
            $attachmentAudience = $attachment->audience;
            $policies[] = $attachment;
            if ($attachmentAudience === Audience::SpecificPeople) {
                $specificLists[] = $attachment->audienceMembers()->pluck('user_id')->map('intval')->all();
            } elseif ($this->audienceRank($attachmentAudience) > $this->audienceRank($audience)) {
                $audience = $attachmentAudience;
            }
        }

        $memberIds = [];
        if ($specificLists !== []) {
            $memberIds = array_shift($specificLists);
            foreach ($specificLists as $list) {
                $memberIds = array_values(array_intersect($memberIds, $list));
            }

            $candidates = User::query()->whereIn('id', $memberIds)->get()->keyBy('id');
            $memberIds = array_values(array_filter(
                $memberIds,
                function (int $id) use ($candidates, $author, $characterId, $audience, $policies): bool {
                    $candidate = $candidates->get($id);
                    if (! $candidate instanceof User
                        || ! $this->requestedPolicyAllows($audience, $author, $candidate, $characterId)) {
                        return false;
                    }

                    return collect($policies)->every(
                        fn (Character|Media|Story $attachment): bool => $attachment->isViewableBy($candidate),
                    );
                },
            ));
            $audience = Audience::SpecificPeople;
        }

        sort($memberIds);

        return [
            'audience' => $audience,
            'discoverable' => $discoverable,
            'member_ids' => $audience === Audience::SpecificPeople ? $memberIds : [],
        ];
    }

    private function requestedPolicyAllows(
        Audience $audience,
        User $author,
        User $candidate,
        ?int $characterId,
    ): bool {
        return match ($audience) {
            Audience::Everyone => true,
            Audience::Followers => FollowGraph::followsIdentity($candidate->id, $author->id, $characterId),
            Audience::Mutuals => FollowGraph::followsIdentity($candidate->id, $author->id, $characterId)
                && FollowGraph::follows($author->id, $candidate->id),
            // Membership in the requested allowlist was already applied when
            // the candidate set was built.
            Audience::SpecificPeople => true,
        };
    }

    private function audienceRank(Audience $audience): int
    {
        return match ($audience) {
            Audience::Everyone => 0,
            Audience::Followers => 1,
            Audience::Mutuals => 2,
            Audience::SpecificPeople => 3,
        };
    }

    /**
     * Resolve the persona a post is published as: a character the author owns.
     */
    private function resolveCharacter(User $author, ?int $characterId): ?Character
    {
        if ($characterId === null) {
            return null;
        }

        $character = Character::query()->find($characterId);
        if ($character === null || $character->user_id !== $author->id) {
            throw ValidationException::withMessages([
                'character_id' => 'You can only post as your own character.',
            ]);
        }

        return $character;
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

            $model = $class::query()->lockForUpdate()->find($id);
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

            if ($model instanceof Media && (! $model->isReady() || ! $model->isApprovedContent())) {
                throw ValidationException::withMessages([
                    "attachments.$i.id" => 'Media must finish review before it can be attached.',
                ]);
            }

            if ($model instanceof Story
                && ($model->status !== StoryStatus::Published || ! $model->isApprovedContent())) {
                throw ValidationException::withMessages([
                    "attachments.$i.id" => 'Stories must be published and approved before they can be attached.',
                ]);
            }

            $resolved[] = $model;
        }

        return $resolved;
    }

    /**
     * Relationship tiers are defined against one effective identity. Mapping a
     * persona-scoped Followers/Mutuals policy onto another persona or the human
     * account silently changes who can see the attachment, so reject instead.
     *
     * @param  list<Model>  $attachments
     */
    private function assertRelationshipIdentitiesMatch(?int $postCharacterId, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Character
                && ! $attachment instanceof Media
                && ! $attachment instanceof Story) {
                continue;
            }

            if (! in_array($attachment->audience, [Audience::Followers, Audience::Mutuals], true)) {
                continue;
            }

            $attachmentCharacterId = match (true) {
                $attachment instanceof Character => (int) $attachment->id,
                $attachment instanceof Media => $attachment->character_id !== null
                    ? (int) $attachment->character_id
                    : null,
                // Story privacy is account-scoped.
                default => null,
            };

            if ($attachmentCharacterId !== $postCharacterId) {
                throw ValidationException::withMessages([
                    'attachments' => 'Relationship-restricted content can only be attached by the same identity.',
                ]);
            }
        }
    }
}

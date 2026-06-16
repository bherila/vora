<?php

namespace App\Support;

use App\Models\Character;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Serializes a Post for API responses. Pure (no I/O). Never exposes moderation
 * state.
 *
 * Enforces the attachment privacy intersection: a privacy-controlled attachment
 * (Media/Story) is only included when the viewer could view it on its own — so a
 * post can never widen the audience of the thing it attaches. Characters and
 * Interests are public profile/tag references and are always shown.
 */
class PostPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function view(Post $post, ?User $viewer): array
    {
        return [
            'id' => $post->id,
            'ulid' => $post->ulid,
            'body' => $post->body,
            'audience' => $post->audience->value,
            'discoverable' => $post->discoverable,
            'author' => self::author($post->user),
            'attachments' => self::attachments($post, $viewer),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function author(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->id, 'display_name' => $user->display_name ?? $user->name];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function attachments(Post $post, ?User $viewer): array
    {
        if (! $post->relationLoaded('attachments')) {
            return [];
        }

        return $post->attachments
            ->map(fn (PostAttachment $attachment): ?array => self::attachment($attachment->attachable, $viewer))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attachment(?Model $attachable, ?User $viewer): ?array
    {
        // Null when the target was deleted out from under the attachment, or when
        // the intersection rule hides a privacy-controlled item from this viewer.
        if ($attachable === null || ! self::canSee($attachable, $viewer)) {
            return null;
        }

        return match (true) {
            $attachable instanceof Character => ['type' => 'character', 'id' => $attachable->id, 'label' => $attachable->display_name],
            $attachable instanceof Interest => ['type' => 'interest', 'id' => $attachable->id, 'label' => $attachable->name],
            $attachable instanceof Media => ['type' => 'media', 'id' => $attachable->id, 'ulid' => $attachable->ulid, 'media_type' => $attachable->type->value, 'label' => $attachable->title],
            $attachable instanceof Story => ['type' => 'story', 'id' => $attachable->id, 'ulid' => $attachable->ulid, 'label' => $attachable->title],
            default => null,
        };
    }

    /**
     * The intersection gate. For privacy-controlled attachments (Media/Story) we
     * defer to that model's own view policy via the Gate, so the rule stays
     * identical to opening the item directly — owner/admin, approval, audience,
     * an active owner, and (for stories) published status — with no duplication.
     * Characters/Interests are public profile/tag references.
     */
    private static function canSee(Model $attachable, ?User $viewer): bool
    {
        if ($attachable instanceof Media || $attachable instanceof Story) {
            return $viewer !== null && Gate::forUser($viewer)->allows('view', $attachable);
        }

        return true;
    }
}

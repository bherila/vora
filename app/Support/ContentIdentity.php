<?php

namespace App\Support;

use App\Models\Character;
use App\Models\Media;
use App\Models\Story;
use App\Models\StoryAuthor;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the surfaced identity of post attachments for pseudonymity checks.
 *
 * Human and Linked-persona attribution deliberately belongs to the account
 * identity. A Separate persona is its own identity. Any link between those
 * identity groups is a correlation leak, regardless of the content's audience.
 */
class ContentIdentity
{
    public static function crossesSeparatePersonaBoundary(?Character $postCharacter, Model $attachment): bool
    {
        $postPersonaId = self::separatePersonaId($postCharacter);
        $attachmentPersonaId = self::attachmentSeparatePersonaId($attachment);

        return ($postPersonaId !== null || $attachmentPersonaId !== null)
            && $postPersonaId !== $attachmentPersonaId;
    }

    private static function attachmentSeparatePersonaId(Model $attachment): ?int
    {
        return match (true) {
            $attachment instanceof Character => self::separatePersonaId($attachment),
            $attachment instanceof Media => self::separatePersonaId(self::mediaCharacter($attachment)),
            $attachment instanceof Story => self::separatePersonaId(self::storyOwnerCharacter($attachment)),
            default => null,
        };
    }

    private static function separatePersonaId(?Character $character): ?int
    {
        return $character instanceof Character && ! $character->is_linked
            ? (int) $character->id
            : null;
    }

    private static function mediaCharacter(Media $media): ?Character
    {
        if (! $media->relationLoaded('character')) {
            $media->load('character:id,is_linked');
        }

        return $media->character;
    }

    private static function storyOwnerCharacter(Story $story): ?Character
    {
        if (! $story->relationLoaded('authors')) {
            $story->load('authors.character:id,is_linked');
        }

        $owner = $story->authors->first(
            fn (StoryAuthor $author): bool => $author->isOwner()
                && $author->isAccepted()
                && (int) $author->user_id === (int) $story->user_id,
        );

        return $owner instanceof StoryAuthor ? $owner->character : null;
    }
}

<?php

namespace App\Services\Post;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
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

        $post = $author->posts()->create([
            'ulid' => (string) Str::ulid(),
            'body' => $body,
            'audience' => $audience->value,
            'discoverable' => $discoverable,
        ]);

        foreach ($resolved as $model) {
            $post->attachments()->create([
                'attachable_type' => $model->getMorphClass(),
                'attachable_id' => $model->getKey(),
            ]);
        }

        $post->syncAudienceMembers($audience === Audience::SpecificPeople ? $audienceUserIds : []);
        $this->auditor->recordCreation($post, $author, $post->privacySnapshot(), $request);

        return $post;
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

            $resolved[] = $model;
        }

        return $resolved;
    }
}

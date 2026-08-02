<?php

namespace App\Services\Post;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CanonicalDiscussionService
{
    public function __construct(private readonly AnnouncementPostService $announcements) {}

    public function resolveFor(Media|Story $content): Post
    {
        return DB::transaction(fn (): Post => $this->resolveLocked($content));
    }

    /**
     * Create the lazy canonical post and its first comment as one observable
     * action. A failed comment write therefore cannot leave an empty discussion
     * behind merely because someone opened the composer.
     *
     * @return array{post: Post, comment: PostComment}
     */
    public function startWithComment(Media|Story $content, User $author, string $body): array
    {
        return DB::transaction(function () use ($content, $author, $body): array {
            $post = $this->resolveLocked($content);
            $comment = $post->comments()->make([
                'user_id' => $author->id,
                'body' => $body,
            ]);
            $comment->moderation_status = ModerationStatus::Approved;
            $comment->save();

            return ['post' => $post, 'comment' => $comment];
        });
    }

    private function resolveLocked(Media|Story $content): Post
    {
        $locked = $content::withTrashed()->whereKey($content->getKey())->lockForUpdate()->first();
        if (! $locked instanceof Media && ! $locked instanceof Story) {
            throw new NotFoundHttpException('Not found.');
        }

        if ($locked->canonical_post_id !== null) {
            $existing = Post::query()->find($locked->canonical_post_id);
            if ($existing instanceof Post) {
                return $existing;
            }
            $locked->forceFill(['canonical_post_id' => null])->save();
        }

        if (! $this->isDiscussionAvailable($locked)) {
            throw new NotFoundHttpException('Not found.');
        }

        $post = new Post([
            'user_id' => $locked->user_id,
            'character_id' => $this->characterId($locked),
            'ulid' => (string) Str::ulid(),
            'body' => $locked instanceof Media ? 'Discuss this media.' : 'Discuss this story.',
            'audience' => $locked->audience,
            'discoverable' => (bool) $locked->discoverable,
            'is_announcement' => false,
            'is_feed_hidden' => true,
        ]);
        $post->moderation_status = ModerationStatus::Approved;
        $post->save();
        $post->attachments()->create([
            'attachable_type' => $locked->getMorphClass(),
            'attachable_id' => $locked->getKey(),
            'position' => 0,
        ]);
        $locked->forceFill(['canonical_post_id' => $post->id])->save();
        $this->announcements->copyPrivacy($post, $locked);

        return $post;
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

    private function characterId(Media|Story $content): ?int
    {
        if ($content instanceof Media) {
            return $content->character_id;
        }

        return $content->authors()
            ->where('user_id', $content->user_id)
            ->where('role', StoryAuthor::ROLE_OWNER)
            ->where('status', StoryAuthor::STATUS_ACCEPTED)
            ->value('character_id');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\StartCanonicalDiscussionRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Notifications\PostCommentedOn;
use App\Services\Media\MediaResponseService;
use App\Services\Post\CanonicalDiscussionService;
use App\Support\PostCommentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CanonicalDiscussionController extends Controller
{
    public function __construct(
        private readonly CanonicalDiscussionService $discussions,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    public function media(StartCanonicalDiscussionRequest $request, string $ulid): JsonResponse
    {
        $media = $request->content();
        abort_unless($media instanceof Media, 404, 'Not found.');

        return $this->start($request, $media);
    }

    public function story(StartCanonicalDiscussionRequest $request, string $ulid): JsonResponse
    {
        $story = $request->content();
        abort_unless($story instanceof Story, 404, 'Not found.');

        return $this->start($request, $story);
    }

    private function start(StartCanonicalDiscussionRequest $request, Media|Story $content): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->discussions->startWithComment(
            $content,
            $user,
            $request->validated('body'),
        );
        $post = $result['post'];
        $comment = $result['comment'];

        if ($post->user_id !== $user->id && $post->user?->notify_post_comment) {
            $post->user->notify(new PostCommentedOn($post, $user));
        }

        $post->loadMissing('character.profilePicture');
        $comment->load(['user:id,name,display_name,profile_picture_media_id', 'user.profilePicture']);
        $comment->setRelation('post', $post);

        return response()->json(['success' => true, 'data' => [
            'post' => $this->postRef($post, $user),
            'comment' => PostCommentPresenter::view($comment, $this->mediaResponder, $user)
                + ['can_delete' => Gate::forUser($user)->allows('delete', $comment)],
        ]], 201);
    }

    /** @return array{id: int, ulid: string, comment_count: int} */
    private function postRef(Post $post, User $viewer): array
    {
        return [
            'id' => $post->id,
            'ulid' => $post->ulid,
            'comment_count' => $post->comments()->threadVisibleTo($viewer)->count(),
        ];
    }
}

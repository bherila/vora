<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\StorePostRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Post\PostService;
use App\Support\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly MediaResponseService $mediaResponder,
    ) {}

    /**
     * A shareable single-post page (resolved client-side by ulid), mirroring the
     * media and story view pages. This is the URL notifications link to.
     */
    public function viewPage(Request $request, string $ulid): View
    {
        return view('posts.show', ['initialData' => [
            'postView' => $this->findByUlidPayload($request, $ulid),
        ]]);
    }

    /**
     * The current user's own posts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $posts = Post::query()
            ->where('user_id', $user?->id)
            ->with(['user.profilePicture', 'character.profilePicture', 'attachments.attachable'])
            ->withEngagementCounts($user)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $posts->map(fn (Post $post): array => PostPresenter::view($post, $user, $this->mediaResponder))->values(),
        ]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = $this->posts->create(
            $user,
            $request->validated('body'),
            $request->audience(),
            $request->discoverable(),
            $request->attachmentsInput(),
            $request->audienceUserIds(),
            $request,
            $request->characterId(),
        );

        $post->load(['user.profilePicture', 'character.profilePicture', 'attachments.attachable']);

        return response()->json([
            'success' => true,
            'data' => PostPresenter::view($post, $user, $this->mediaResponder),
        ], 201);
    }

    /**
     * Resolve a shareable post by ulid, honouring audience + moderation.
     */
    public function showByUlid(Request $request, string $ulid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->findByUlidPayload($request, $ulid),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findByUlidPayload(Request $request, string $ulid): array
    {
        $post = Post::query()->where('ulid', $ulid)->withEngagementCounts($request->user())->first();
        $this->authorizeOr404('view', $post);

        $post->load(['user.profilePicture', 'character.profilePicture', 'attachments.attachable']);

        return PostPresenter::view($post, $request->user(), $this->mediaResponder);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorizeOr404('delete', $post);

        $post->delete();

        return response()->json(['success' => true, 'message' => 'Post deleted.']);
    }
}

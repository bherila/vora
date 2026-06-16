<?php

namespace App\Http\Controllers;

use App\Http\Requests\Post\StorePostRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Post\PostService;
use App\Support\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    /**
     * The current user's own posts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $posts = Post::query()
            ->where('user_id', $user?->id)
            ->with(['user', 'attachments.attachable'])
            ->withEngagementCounts($user)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $posts->map(fn (Post $post): array => PostPresenter::view($post, $user))->values(),
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
        );

        $post->load(['user', 'attachments.attachable']);

        return response()->json([
            'success' => true,
            'data' => PostPresenter::view($post, $user),
        ], 201);
    }

    /**
     * Resolve a shareable post by ulid, honouring audience + moderation.
     */
    public function showByUlid(Request $request, string $ulid): JsonResponse
    {
        $post = Post::query()->where('ulid', $ulid)->withEngagementCounts($request->user())->firstOrFail();
        Gate::authorize('view', $post);

        $post->load(['user', 'attachments.attachable']);

        return response()->json([
            'success' => true,
            'data' => PostPresenter::view($post, $request->user()),
        ]);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);

        $post->delete();

        return response()->json(['success' => true, 'message' => 'Post deleted.']);
    }
}

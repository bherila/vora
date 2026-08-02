<?php

namespace App\Http\Controllers;

use App\Enums\RestrictionCapability;
use App\Http\Requests\Post\StorePostRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Moderation\RestrictionGate;
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
        private readonly RestrictionGate $restrictions,
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
            ->with(['user.profilePicture', 'character.profilePicture', 'contextInterest', 'attachments.attachable'])
            ->withEngagementCounts($user)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $posts->map(fn (Post $post): array => $this->present($post, $user))->values(),
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
            $request->contextInterestId(),
        );

        $post->load(['user.profilePicture', 'character.profilePicture', 'contextInterest', 'attachments.attachable']);

        return response()->json([
            'success' => true,
            'data' => $this->present($post, $user),
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

        $post->load(['user.profilePicture', 'character.profilePicture', 'contextInterest', 'attachments.attachable']);

        return $this->present($post, $request->user());
    }

    /** @return array<string, mixed> */
    private function present(Post $post, ?User $viewer): array
    {
        return PostPresenter::view(
            $post,
            $viewer,
            $this->mediaResponder,
            canViewNonOwnedMedia: ! ($viewer instanceof User)
                || ! $this->restrictions->denies($viewer, RestrictionCapability::MediaView),
        );
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorizeOr404('delete', $post);

        $post->delete();

        return response()->json(['success' => true, 'message' => 'Post deleted.']);
    }
}

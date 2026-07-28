<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\AdminMediaResponseService;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Support\PaginationMeta;
use App\Support\PostPresenter;
use App\Support\StoryPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDeletedContentController extends Controller
{
    private const TYPES = ['media', 'stories', 'characters', 'posts'];

    public function __construct(
        private readonly AdminMediaResponseService $adminMedia,
        private readonly MediaResponseService $mediaResponder,
        private readonly MediaService $mediaService,
    ) {}

    public function index(): View
    {
        return view('admin.deleted-content');
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $type = $this->type($request);

        return response()->json([
            'success' => true,
            ...match ($type) {
                'media' => $this->mediaPayload(),
                'stories' => $this->storiesPayload(),
                'characters' => $this->charactersPayload(),
                'posts' => $this->postsPayload($request),
            },
        ]);
    }

    public function restore(string $type, int $id): JsonResponse
    {
        $model = $this->findDeleted($this->normalizeType($type), $id);
        $model->restore();

        return response()->json(['success' => true, 'message' => 'Content restored.']);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $normalized = $this->normalizeType($type);
        $model = $this->findDeleted($normalized, $id);

        if ($model instanceof Media) {
            $this->mediaService->delete($model);
        } else {
            $model->forceDelete();
        }

        return response()->json(['success' => true, 'message' => 'Content permanently deleted.']);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function mediaPayload(): array
    {
        $paginator = Media::onlyTrashed()
            ->with([
                'interests',
                'character',
                'user' => fn ($query) => $query->withTrashed(),
            ])
            ->latest('deleted_at')
            ->paginate((int) config('media.page_size', 24));

        return [
            'data' => collect($paginator->items())
                ->map(fn (Media $media): array => $this->adminMedia->item($media, resolveHls: false, downloadAll: true) + [
                    'content_type' => 'media',
                    'deleted_at' => $media->deleted_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function storiesPayload(): array
    {
        $paginator = Story::onlyTrashed()
            ->with([
                'user' => fn ($query) => $query->withTrashed(),
                'interests',
                'involvements.involvable',
                'authors.user' => fn ($query) => $query->withTrashed(),
                'authors.character',
            ])
            ->withCount('nodes')
            ->latest('deleted_at')
            ->paginate((int) config('media.page_size', 24));

        return [
            'data' => collect($paginator->items())
                ->map(fn (Story $story): array => StoryPresenter::adminView($story) + [
                    'content_type' => 'story',
                    'deleted_at' => $story->deleted_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function charactersPayload(): array
    {
        $paginator = Character::onlyTrashed()
            ->with([
                'user' => fn ($query) => $query->withTrashed(),
                'profilePicture',
                'audienceMembers',
            ])
            ->withCount(['media' => fn ($query) => $query->withTrashed()])
            ->latest('deleted_at')
            ->paginate((int) config('media.page_size', 24));

        return [
            'data' => collect($paginator->items())
                ->map(fn (Character $character): array => $this->characterPayload($character))
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function postsPayload(Request $request): array
    {
        /** @var User $admin */
        $admin = $request->user();

        $paginator = Post::onlyTrashed()
            ->with([
                'user' => fn ($query) => $query->withTrashed(),
                'character.profilePicture',
                'attachments.attachable',
            ])
            ->withAdminEngagementCounts($admin)
            ->latest('deleted_at')
            ->paginate((int) config('media.page_size', 24));

        return [
            'data' => collect($paginator->items())
                ->map(fn (Post $post): array => PostPresenter::adminView($post, $admin, $this->mediaResponder) + [
                    'content_type' => 'post',
                    'deleted_at' => $post->deleted_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => PaginationMeta::from($paginator),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterPayload(Character $character): array
    {
        $picture = $character->profilePicture;

        return [
            'content_type' => 'character',
            'id' => $character->id,
            'display_name' => $character->display_name,
            'description' => $character->description,
            'audience' => $character->audience->value,
            'discoverable' => (bool) $character->discoverable,
            'profile_picture' => $picture instanceof Media ? $this->mediaResponder->item($picture, resolveHls: false) : null,
            'media_count' => (int) ($character->media_count ?? 0),
            'user' => [
                'id' => $character->user_id,
                'name' => $character->user?->name,
                'email' => $character->user?->email,
            ],
            'deleted_at' => $character->deleted_at?->toIso8601String(),
        ];
    }

    private function type(Request $request): string
    {
        return $this->normalizeType((string) $request->query('type', 'media'));
    }

    private function normalizeType(string $type): string
    {
        abort_unless(in_array($type, self::TYPES, true), 404, 'Not found.');

        return $type;
    }

    private function findDeleted(string $type, int $id): Model
    {
        return match ($type) {
            'media' => Media::onlyTrashed()->findOrFail($id),
            'stories' => Story::onlyTrashed()->findOrFail($id),
            'characters' => Character::onlyTrashed()->findOrFail($id),
            'posts' => Post::onlyTrashed()->findOrFail($id),
        };
    }
}

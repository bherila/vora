<?php

namespace App\Http\Controllers;

use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Media\HlsMappingService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use App\Support\MediaPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaService $media,
        private readonly HlsMappingService $hls,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * The signed-in user's media library page.
     */
    public function library(): View
    {
        return view('user.media');
    }

    /**
     * A shareable single-media view page (resolved client-side by ulid).
     */
    public function viewPage(string $ulid): View
    {
        return view('media.show', ['ulid' => $ulid]);
    }

    /**
     * List the current user's own media (every status).
     */
    public function index(Request $request): JsonResponse
    {
        $items = Media::query()
            ->where('user_id', $request->user()->id)
            ->with('interests')
            ->latest()
            ->get()
            ->map(fn (Media $m): array => MediaPresenter::ownerView($m, $this->extrasFor($m)));

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * Create a pending media record and return a presigned upload URL.
     */
    public function store(StoreMediaRequest $request): JsonResponse
    {
        $result = $this->uploads->createPendingUpload(
            $request->user(),
            MediaType::from($request->validated('type')),
            $request->validated('filename'),
            $request->validated('content_type'),
            $request->validated('title'),
            Visibility::from($request->validated('visibility')),
            $request->interestIds(),
        );

        $media = $result['media']->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
            'upload_url' => $result['upload_url'],
        ], 201);
    }

    /**
     * Confirm an upload finished; verifies the object landed in storage.
     */
    public function complete(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('complete', $media);

        if (! $this->uploads->completeUpload($media)) {
            return response()->json([
                'success' => false,
                'message' => 'Uploaded file was not found in storage.',
            ], 422);
        }

        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
        ]);
    }

    /**
     * Show a single item the current user owns (by id).
     */
    public function show(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('view', $media);
        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
        ]);
    }

    /**
     * Resolve a shareable item by its ulid, honouring visibility.
     */
    public function showByUlid(Request $request, string $ulid): JsonResponse
    {
        $media = Media::query()->where('ulid', $ulid)->firstOrFail();
        Gate::authorize('view', $media);
        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
        ]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('delete', $media);

        $this->media->delete($media);

        return response()->json(['success' => true, 'message' => 'Media deleted.']);
    }

    /**
     * Compute the signed view URL and (for videos) HLS playback status.
     *
     * @return array{url: ?string, video: ?array<string, mixed>}
     */
    private function extrasFor(Media $media): array
    {
        $extras = ['url' => null, 'video' => null];

        if (! $media->isReady()) {
            return $extras;
        }

        $extras['url'] = $this->storage->getSignedViewUrl(
            $media->disk,
            $media->object_key,
            (int) config('media.view_url_ttl', 60),
            $media->mime_type,
        );

        if ($media->type->isVideo()) {
            $extras['video'] = $this->hls->resolve($media);
        }

        return $extras;
    }
}

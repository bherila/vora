<?php

namespace App\Http\Controllers;

use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Http\Requests\Media\AbortMultipartRequest;
use App\Http\Requests\Media\CompleteMultipartRequest;
use App\Http\Requests\Media\PresignPartRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Media;
use App\Services\FileStorageService;
use App\Services\Media\HlsService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use App\Support\MediaPresenter;
use App\Support\PaginationMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaService $media,
        private readonly HlsService $hls,
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
     * List the current user's own media (every status), newest first, paginated.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = Media::query()
            ->where('user_id', $request->user()->id)
            ->with('interests')
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $data = collect($paginator->items())
            // resolveHls: false — listings must not do a per-item R2 read.
            ->map(fn (Media $m): array => MediaPresenter::ownerView($m, $this->extrasFor($m, resolveHls: false)))
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    /**
     * Create a pending media record and return upload instructions: a single
     * presigned PUT, or — for large files — a multipart upload to chunk against.
     */
    public function store(StoreMediaRequest $request): JsonResponse
    {
        $type = MediaType::from($request->validated('type'));
        $visibility = Visibility::from($request->validated('visibility'));
        $size = (int) ($request->validated('size') ?? 0);

        if ($size >= (int) config('media.multipart_threshold', PHP_INT_MAX)) {
            $result = $this->uploads->createPendingMultipart(
                $request->user(),
                $type,
                $request->validated('filename'),
                $request->validated('content_type'),
                $request->validated('title'),
                $visibility,
                $request->interestIds(),
            );

            $media = $result['media']->load('interests');

            return response()->json([
                'success' => true,
                'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
                'multipart' => [
                    'upload_id' => $result['upload_id'],
                    'part_size' => $result['part_size'],
                ],
            ], 201);
        }

        $result = $this->uploads->createPendingUpload(
            $request->user(),
            $type,
            $request->validated('filename'),
            $request->validated('content_type'),
            $request->validated('title'),
            $visibility,
            $request->interestIds(),
        );

        $media = $result['media']->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
        ], 201);
    }

    /**
     * Presign one part of an in-flight multipart upload.
     */
    public function presignPart(PresignPartRequest $request, Media $media): JsonResponse
    {
        Gate::authorize('complete', $media);

        $url = $this->uploads->presignPart(
            $media,
            $request->validated('upload_id'),
            (int) $request->validated('part_number'),
        );

        return response()->json(['success' => true, 'url' => $url]);
    }

    /**
     * Finalise a multipart upload from the client's collected part ETags.
     */
    public function completeMultipart(CompleteMultipartRequest $request, Media $media): JsonResponse
    {
        Gate::authorize('complete', $media);

        $parts = array_map(
            fn (array $p): array => ['PartNumber' => (int) $p['part_number'], 'ETag' => (string) $p['etag']],
            $request->validated('parts'),
        );

        if (! $this->uploads->completeMultipart($media, $request->validated('upload_id'), $parts)) {
            return response()->json([
                'success' => false,
                'message' => 'Upload could not be verified — the file is missing or exceeds the size limit.',
            ], 422);
        }

        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => MediaPresenter::ownerView($media, $this->extrasFor($media)),
        ]);
    }

    /**
     * Abort an in-flight multipart upload and discard the pending record.
     */
    public function abortMultipart(AbortMultipartRequest $request, Media $media): JsonResponse
    {
        Gate::authorize('complete', $media);

        $this->uploads->abortMultipart($media, $request->validated('upload_id'));

        return response()->json(['success' => true, 'message' => 'Upload aborted.']);
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
                'message' => 'Upload could not be verified — the file is missing or exceeds the size limit.',
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
    private function extrasFor(Media $media, bool $resolveHls = true): array
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
            $extras['video'] = $this->hls->status($media, $resolveHls);
        }

        return $extras;
    }

    /**
     * Authenticated HLS playback proxy. Manifests (`.m3u8`) are returned inline
     * with child URIs rewritten back through this endpoint; segment/init objects
     * are 302-redirected to short-lived presigned R2 URLs so the app does not
     * carry segment bandwidth.
     */
    public function streamHls(Request $request, Media $media, string $path = 'master.m3u8'): Response|RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $media);

        if (! $media->type->isVideo()) {
            return response()->json(['success' => false, 'message' => 'Not a video.'], 404);
        }

        if (! $this->hls->isSafeRelativePath($path)) {
            return response()->json(['success' => false, 'message' => 'Invalid path.'], 422);
        }

        if (! $this->hls->ensureResolved($media)) {
            return response()->json(['success' => false, 'message' => 'HLS not available for this video.'], 404);
        }

        if ($this->hls->isManifestPath($path)) {
            $base = route('media.hls', ['media' => $media->id]);
            $manifest = $this->hls->manifest($media, $path, fn (string $child): string => $base.'/'.$child);

            if ($manifest === null) {
                return response()->json(['success' => false, 'message' => 'Manifest not found.'], 404);
            }

            return response($manifest['body'], 200, [
                'Content-Type' => $manifest['contentType'],
                'Cache-Control' => 'private, max-age=10',
            ]);
        }

        $url = $this->hls->segmentUrl($media, $path);
        if ($url === null) {
            return response()->json(['success' => false, 'message' => 'Segment not found.'], 404);
        }

        return redirect()->away($url, 302);
    }
}

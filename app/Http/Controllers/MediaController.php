<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Media;
use App\Models\MediaPlaybackAuditLog;
use App\Models\User;
use App\Services\Media\HlsService;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use App\Services\Privacy\PrivacyAuditor;
use App\Support\MediaFilter;
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
        private readonly MediaResponseService $responder,
        private readonly PrivacyAuditor $auditor,
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
     * Accepts the shared type/interest discovery filters.
     */
    public function index(ListMediaRequest $request): JsonResponse
    {
        $query = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('user_id', $request->user()->id)
            ->with('interests')
            ->latest();

        $paginator = MediaFilter::fromRequest($request)
            ->applyTo($query)
            ->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            ...$this->responder->page($paginator),
        ]);
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
            $request->audience(),
            $request->interestIds(),
            (bool) $request->validated('has_thumbnail', false),
            $request->validated('perceptual_hash'),
            discoverable: $request->discoverable(),
        );

        $media = $result['media'];

        // Apply the allowlist (empty unless the SpecificPeople audience) and
        // record the initial privacy policy for audit.
        $media->syncAudienceMembers(
            $request->audience() === Audience::SpecificPeople ? $request->audienceUserIds() : []
        );
        $this->auditor->recordCreation($media, $request->user(), $media->privacySnapshot(), $request);

        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
            'thumbnail_upload_url' => $result['thumbnail_upload_url'],
            'thumbnail_upload_headers' => $result['thumbnail_upload_headers'],
        ], 201);
    }

    /**
     * Confirm an upload finished; verifies the object landed in storage.
     */
    public function complete(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('complete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->uploads->completeUpload($media)) {
            return response()->json([
                'success' => false,
                'message' => 'Upload could not be verified — the file is missing or exceeds the size limit.',
            ], 422);
        }

        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media),
        ]);
    }

    /**
     * Show a single item the current user owns (by id).
     */
    public function show(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('view', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $media->load('interests');

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media),
        ]);
    }

    /**
     * Resolve a shareable item by its ulid, honouring visibility.
     */
    public function showByUlid(Request $request, string $ulid): JsonResponse
    {
        $media = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('ulid', $ulid)
            ->firstOrFail();
        Gate::authorize('view', $media);
        $media->load('interests');
        $viewer = $request->user();
        $includeOriginalVideoUrl = $viewer instanceof User
            && ($media->user_id === $viewer->id || $viewer->isAdmin());

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media, includeOriginalVideoUrl: $includeOriginalVideoUrl),
        ]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('delete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $this->media->delete($media);

        return response()->json(['success' => true, 'message' => 'Media deleted.']);
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

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

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

            $this->auditPlayback($request, $media, 'hls_manifest', $path, [
                'content_type' => $manifest['contentType'],
            ]);

            return response($manifest['body'], 200, [
                'Content-Type' => $manifest['contentType'],
                'Cache-Control' => 'private, max-age=10',
            ]);
        }

        $url = $this->hls->segmentUrl($media, $path);
        if ($url === null) {
            return response()->json(['success' => false, 'message' => 'Segment not found.'], 404);
        }

        $this->auditPlayback($request, $media, 'hls_segment_redirect', $path);

        return redirect()->away($url, 302);
    }

    /**
     * Keep a lightweight audit trail of successful HLS playback access. Do not
     * persist the generated R2 URL; it is a credential-bearing artifact.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function auditPlayback(Request $request, Media $media, string $action, string $path, array $metadata = []): void
    {
        MediaPlaybackAuditLog::query()->create([
            'media_id' => $media->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'path' => $path,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}

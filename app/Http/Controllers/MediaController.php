<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Http\Requests\Media\AbortMultipartMediaUploadRequest;
use App\Http\Requests\Media\CompleteMultipartMediaUploadRequest;
use App\Http\Requests\Media\InitMultipartMediaUploadRequest;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\PresignMultipartMediaPartsRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Media;
use App\Models\MediaPlaybackAuditLog;
use App\Models\User;
use App\Policies\MediaPolicy;
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
    public function library(Request $request): View
    {
        return view('user.media', ['initialData' => [
            'userMedia' => [
                'last_interest_ids' => array_values(array_map('intval', $request->user()->last_media_interest_ids ?? [])),
                ...$this->indexPayload($request),
            ],
        ]]);
    }

    /**
     * A shareable single-media view page (resolved client-side by ulid).
     */
    public function viewPage(Request $request, string $ulid): View
    {
        return view('media.show', ['initialData' => [
            'mediaView' => $this->findByUlidPayload($request, $ulid),
        ]]);
    }

    /**
     * List the current user's own media (every status), newest first, paginated.
     * Accepts the shared type/interest discovery filters.
     */
    public function index(ListMediaRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->indexPayload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(ListMediaRequest|Request $request): array
    {
        $query = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('user_id', $request->user()->id)
            ->with('interests')
            ->latest();

        $paginator = MediaFilter::fromRequest($request)
            ->applyTo($query)
            ->paginate((int) config('media.page_size', 24));

        return $this->responder->page($paginator, includeOriginalVideoUrls: true);
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
            'data' => $this->responder->item($media, includeOriginalVideoUrl: true),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
            'thumbnail_upload_url' => $result['thumbnail_upload_url'],
            'thumbnail_upload_headers' => $result['thumbnail_upload_headers'],
            'multipart' => [
                'enabled' => (bool) config('media.multipart.enabled', true)
                    && MediaType::from($request->validated('type'))->isVideo(),
                'threshold_bytes' => (int) config('media.multipart.threshold_bytes'),
                'part_size_bytes' => (int) config('media.multipart.part_size_bytes'),
            ],
        ], 201);
    }

    public function initMultipart(InitMultipartMediaUploadRequest $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('complete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! (bool) config('media.multipart.enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Multipart uploads are disabled.'], 422);
        }

        $session = $this->uploads->initMultipartUpload($media);
        if ($session === null) {
            return response()->json(['success' => false, 'message' => 'This upload cannot start a multipart session.'], 422);
        }

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function presignMultipartParts(PresignMultipartMediaPartsRequest $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('complete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $parts = $this->uploads->signedMultipartPartUrls(
            $media,
            (string) $request->validated('upload_id'),
            $request->partNumbers(),
        );

        if ($parts === null) {
            return response()->json(['success' => false, 'message' => 'Multipart upload session was not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $parts]);
    }

    public function completeMultipart(CompleteMultipartMediaUploadRequest $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('complete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $completed = $this->uploads->completeMultipartUpload(
            $media,
            (string) $request->validated('upload_id'),
            $request->parts(),
        );

        if (! $completed) {
            return response()->json(['success' => false, 'message' => 'Multipart upload could not be completed.'], 422);
        }

        $media->refresh()->load('interests');

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media, includeOriginalVideoUrl: true),
        ]);
    }

    public function abortMultipart(AbortMultipartMediaUploadRequest $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('complete', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->uploads->abortMultipartUpload($media, (string) $request->validated('upload_id'))) {
            return response()->json(['success' => false, 'message' => 'Multipart upload session was not found.'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Confirm an upload finished; verifies the object landed in storage.
     */
    public function complete(Request $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('complete', $media);

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
            'data' => $this->responder->item($media, includeOriginalVideoUrl: true),
        ]);
    }

    /**
     * Show a single item by id. Owners/admins get video originals; other
     * authorized viewers only get the HLS playback surface.
     */
    public function show(Request $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('view', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $media->load('interests');
        $viewer = $request->user();
        $includeOriginalVideoUrl = $viewer instanceof User
            && ($media->user_id === $viewer->id || $viewer->isAdmin());

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media, includeOriginalVideoUrl: $includeOriginalVideoUrl),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findByUlidPayload(Request $request, string $ulid): array
    {
        $media = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('ulid', $ulid)
            ->first();
        // first() + a generic abort (not firstOrFail) so a missing ulid yields the
        // same body as a hidden one — firstOrFail leaks the model name.
        if ($media === null) {
            abort(404, 'Not found.');
        }
        $this->authorizeOr404('view', $media);
        $media->load('interests');
        $viewer = $request->user();
        $includeOriginalVideoUrl = $viewer instanceof User
            && ($media->user_id === $viewer->id || $viewer->isAdmin());

        return $this->responder->item($media, includeOriginalVideoUrl: $includeOriginalVideoUrl);
    }

    /**
     * Resolve a shareable item by its ulid, honouring visibility.
     */
    public function showByUlid(Request $request, string $ulid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->findByUlidPayload($request, $ulid),
        ]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('delete', $media);

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
        $this->authorizeOr404('view', $media);

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
     * Authorize a media action, answering 404 (not 403) when the viewer may not
     * act on the row. This keeps "exists but isn't yours / isn't approved /
     * is private" indistinguishable from "doesn't exist", so numeric ids and
     * ulids can't be used as an existence oracle. Admins satisfy the policy via
     * {@see MediaPolicy::before()}, so their visibility is unchanged.
     */
    private function authorizeOr404(string $ability, Media $media): void
    {
        // Same generic body as a missing-row 404 (see the {media} route binding in
        // AppServiceProvider) so the response can't be diffed to tell "hidden" from
        // "does not exist".
        abort_unless(Gate::allows($ability, $media), 404, 'Not found.');
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

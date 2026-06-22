<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Http\Requests\Media\AbortMultipartMediaUploadRequest;
use App\Http\Requests\Media\BulkMediaRequest;
use App\Http\Requests\Media\BulkUpdateMediaRequest;
use App\Http\Requests\Media\CompleteMultipartMediaUploadRequest;
use App\Http\Requests\Media\InitMultipartMediaUploadRequest;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\PresignMultipartMediaPartsRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\MediaPlaybackAuditLog;
use App\Models\User;
use App\Policies\MediaPolicy;
use App\Services\Favorites\FavoriteService;
use App\Services\Media\HlsService;
use App\Services\Media\MediaDuplicateService;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use App\Services\Privacy\PrivacyAuditor;
use App\Support\MediaFilter;
use App\Support\UserPresenter;
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
        private readonly MediaDuplicateService $duplicates,
        private readonly MediaResponseService $responder,
        private readonly PrivacyAuditor $auditor,
        private readonly FavoriteService $favorites,
    ) {}

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
            ->with(['character:id,display_name', 'interests'])
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
        $character = $request->character();
        $audience = $character instanceof Character ? $character->audience : $request->audience();
        $discoverable = $character instanceof Character ? $character->discoverable : $request->discoverable();
        $fileHash = $request->validated('file_hash');

        // Block a byte-identical re-upload before creating anything (per-owner).
        $existing = $this->duplicates->findExactDuplicate($request->user(), $fileHash);
        if ($existing !== null) {
            return response()->json([
                'success' => false,
                'message' => 'You have already uploaded this file.',
                'duplicate' => ['id' => $existing->id, 'ulid' => $existing->ulid, 'title' => $existing->title],
            ], 409);
        }

        $result = $this->uploads->createPendingUpload(
            $request->user(),
            MediaType::from($request->validated('type')),
            $request->validated('filename'),
            $request->validated('content_type'),
            $request->validated('title'),
            $audience,
            $request->interestIds(),
            (bool) $request->validated('has_thumbnail', false),
            $request->validated('perceptual_hash'),
            discoverable: $discoverable,
            characterId: $character?->id,
            fileHash: $fileHash,
        );

        $media = $result['media'];

        // Apply the allowlist. Character-associated media inherits from the
        // character; standalone media uses its own specific-people selection.
        $media->syncAudienceMembers($character instanceof Character
            ? $this->characterAudienceUserIds($character)
            : ($audience === Audience::SpecificPeople ? $request->audienceUserIds() : []));
        $this->auditor->recordCreation($media, $request->user(), $media->privacySnapshot(), $request);

        // Flag a likely near-duplicate photo for admin review (never blocking).
        $this->duplicates->flagPerceptualDuplicate($media);

        $media->load(['character:id,display_name', 'interests']);

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

    /**
     * @return list<int>
     */
    private function characterAudienceUserIds(Character $character): array
    {
        if ($character->audience !== Audience::SpecificPeople) {
            return [];
        }

        return $character->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all();
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
     * Show a single item by id. The uploader and admins get video originals;
     * other authorized viewers only get the HLS playback surface.
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
        $media->load(['interests', 'user']);
        $viewer = $request->user();
        $includeOriginalVideoUrl = $viewer instanceof User
            && ($media->user_id === $viewer->id || $viewer->isAdmin());

        $payload = $this->responder->item($media, includeOriginalVideoUrl: $includeOriginalVideoUrl);
        $payload['favorited'] = $viewer instanceof User && Favorite::query()
            ->where('user_id', $viewer->id)
            ->where('favoritable_type', $media->getMorphClass())
            ->where('favoritable_id', $media->id)
            ->exists();
        $payload['favorite_count'] = $this->favorites->countFor($media);

        // Uploader context so the single-media view can frame the item inside the
        // owner's profile (a header back to Explore + a link to that profile).
        $owner = $media->user;
        $isSelf = $viewer instanceof User && $owner instanceof User && $owner->id === $viewer->id;
        $payload['owner'] = $owner instanceof User ? [
            'id' => $owner->id,
            'display_name' => $owner->display_name ?: $owner->name,
            'avatar_url' => UserPresenter::avatarUrl($owner, $this->responder, $viewer),
            'href' => $isSelf ? route('me', [], false) : route('users.profile', $owner, false),
            'is_self' => $isSelf,
        ] : null;
        // Anyone signed in who isn't the owner can report the item for abuse.
        $payload['can_report'] = $viewer instanceof User && ! $isSelf;

        return $payload;
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

        $this->media->softDelete($media);

        return response()->json(['success' => true, 'message' => 'Media deleted.']);
    }

    /**
     * Edit a single gallery item (title, privacy, character). Character media
     * inherits the character's privacy, so privacy fields are ignored while a
     * character is attached — detach first to set privacy directly.
     */
    public function update(UpdateMediaRequest $request, Media $media): JsonResponse
    {
        $this->authorizeOr404('update', $media);

        if (! $media->isGalleryMedia()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $user = $request->user();
        $privacyBefore = $media->privacySnapshot();

        if ($request->has('title')) {
            $title = $request->input('title');
            $media->title = is_string($title) && trim($title) !== '' ? trim($title) : null;
        }

        if ($request->has('character_id')) {
            $character = $request->character();
            if ($character instanceof Character) {
                $media->character_id = $character->id;
                $media->audience = $character->audience;
                $media->discoverable = $character->discoverable;
                $media->save();
                $media->syncAudienceMembers($this->characterAudienceUserIds($character));
            } else {
                $media->character_id = null;
                $media->save();
            }
        } elseif ($request->has('audience')) {
            if ($media->character_id !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Character media inherits character privacy. Detach the character to set privacy directly.',
                ], 422);
            }
            $media->audience = $request->audience();
            $media->discoverable = $request->discoverable();
            $media->save();
            $media->syncAudienceMembers($media->audience === Audience::SpecificPeople ? $request->audienceUserIds() : []);
        } else {
            $media->save();
        }

        $this->auditor->record($media, $user, $privacyBefore, $media->privacySnapshot(), $request);

        return response()->json([
            'success' => true,
            'data' => $this->responder->item(
                $media->fresh(['character:id,display_name', 'interests']),
                resolveHls: false,
                includeOriginalVideoUrl: true,
            ),
        ]);
    }

    public function bulkUpdate(BulkUpdateMediaRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $media = Media::query()
            ->whereIn('id', $request->mediaIds())
            ->where('user_id', $user->id)
            ->where('purpose', MediaPurpose::Gallery->value)
            ->with(['audienceMembers', 'character:id,display_name', 'interests'])
            ->get();

        $action = $request->action();
        $character = $request->character();
        $characterAudienceUserIds = $character instanceof Character ? $this->characterAudienceUserIds($character) : [];

        foreach ($media as $item) {
            $privacyBefore = $item->privacySnapshot();

            if ($action === BulkUpdateMediaRequest::ACTION_ASSIGN_CHARACTER && $character instanceof Character) {
                $item->character_id = $character->id;
                $item->audience = $character->audience;
                $item->discoverable = $character->discoverable;
                $item->save();
                $item->syncAudienceMembers($characterAudienceUserIds);
                $this->auditor->record($item, $user, $privacyBefore, $item->privacySnapshot(), $request);
            } elseif ($action === BulkUpdateMediaRequest::ACTION_CLEAR_CHARACTER) {
                $item->character_id = null;
                $item->save();
            } elseif ($action === BulkUpdateMediaRequest::ACTION_SET_PRIVACY) {
                $item->audience = $request->audience();
                $item->discoverable = $request->discoverable();
                $item->save();
                $item->syncAudienceMembers($item->audience === Audience::SpecificPeople ? $request->audienceUserIds() : []);
                $this->auditor->record($item, $user, $privacyBefore, $item->privacySnapshot(), $request);
            }
        }

        $fresh = Media::query()
            ->whereIn('id', $request->mediaIds())
            ->where('user_id', $user->id)
            ->with(['character:id,display_name', 'interests'])
            ->latest()
            ->get()
            ->map(fn (Media $item): array => $this->responder->item($item, resolveHls: false, includeOriginalVideoUrl: true))
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $fresh]);
    }

    public function bulkDestroy(BulkMediaRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $media = Media::query()
            ->whereIn('id', $request->mediaIds())
            ->where('user_id', $user->id)
            ->where('purpose', MediaPurpose::Gallery->value)
            ->get();

        foreach ($media as $item) {
            $this->media->softDelete($item);
        }

        return response()->json([
            'success' => true,
            'message' => 'Media deleted.',
            'deleted_count' => $media->count(),
        ]);
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

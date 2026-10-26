<?php

namespace App\Http\Controllers\Events;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Enums\RestrictionCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventMediaRequest;
use App\Http\Requests\Events\EventPhotoVariantsRequest;
use App\Http\Requests\Events\EventUploadRequest;
use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\Events\EventPhotoVersions;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventSettings;
use App\Services\Favorites\FavoriteService;
use App\Services\FileStorageService;
use App\Services\Media\MediaUploadService;
use App\Services\Moderation\RestrictionGate;
use App\Support\BlockGraph;
use App\Support\InstanceFeatures;
use App\Support\PaginationMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventMediaController extends Controller
{
    public function __construct(
        private readonly EventPresenter $presenter,
        private readonly FavoriteService $favorites,
        private readonly EventSettings $settings,
        private readonly RestrictionGate $restrictions,
        private readonly EventPhotoVersions $photoVersions,
    ) {}

    /** @return Builder<Media> */
    private function visibleMedia(User $viewer): Builder
    {
        // Admins also reach finished uploads awaiting review, to moderate in place.
        $reviewer = $viewer->isAdmin();
        $query = Media::query()->where('purpose', MediaPurpose::Event->value)->viewableBy($viewer)
            ->where(function (Builder $ready) use ($viewer, $reviewer): void {
                $ready->where('user_id', $viewer->id)->orWhere(function (Builder $published): void {
                    $published->where('upload_status', 'ready')->where('moderation_status', 'approved')
                        ->whereHas('user', fn (Builder $owner) => $owner->active()->whereNotNull('approved_at'));
                });
                if ($reviewer) {
                    $ready->orWhere(fn (Builder $pending) => $pending->where('upload_status', 'ready')->where('moderation_status', ModerationStatus::Pending->value));
                }
            });
        if ($this->restrictions->denies($viewer, RestrictionCapability::MediaView)) {
            $query->where('user_id', $viewer->id);
        }

        return $query->with(['event.members:id', 'user', 'interests']);
    }

    public function index(EventMediaRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $query = $this->visibleMedia($viewer);
        // The review queue lists only finished uploads awaiting review; the
        // ordinary gallery stays what every attendee sees.
        if ($request->boolean('review')) {
            $query->where('upload_status', 'ready')->where('moderation_status', ModerationStatus::Pending->value);
        } else {
            $query->where(fn (Builder $published) => $published->where('user_id', $viewer->id)->orWhere('moderation_status', '!=', ModerationStatus::Pending->value));
        }
        if ($ulid = $request->validated('event_ulid')) {
            $query->whereHas('event', fn (Builder $event) => $event->where('ulid', $ulid));
        }
        if ($id = $request->validated('attendee_id')) {
            $query->where('user_id', (int) $id);
        }
        $curators = null;
        if ($request->boolean('featured')) {
            $curators = $this->settings->featuredCurators($viewer)->pluck('id')->all();
        }
        if ($id = $request->validated('favorites_of')) {
            $owner = User::query()->active()->whereNotNull('approved_at')->find((int) $id);
            $curators = $owner instanceof User && BlockGraph::canViewIdentity($viewer, $owner->id) ? [$owner->id] : [];
        }
        if ($curators !== null) {
            $query->whereExists(fn ($favorites) => $favorites->selectRaw('1')->from('favorites')
                ->whereColumn('favorites.favoritable_id', 'media.id')->where('favorites.favoritable_type', (new Media)->getMorphClass())
                ->whereIn('favorites.user_id', $curators));
        }
        $query = $this->photoVersions->collapse($query);
        // Default to the order things happened; "recent" keeps upload order for catching up.
        $page = ($request->validated('sort') === 'recent'
            ? $query->latest('media.created_at')->orderByDesc('media.id')
            : $query->orderBy('media.gallery_sort_at')->orderBy('media.id'))->paginate(24);
        $versions = $this->photoVersions->summaries($viewer, $page->getCollection());
        $saved = $viewer->libraryItems()->whereIn('media_id', $page->getCollection()->modelKeys())->pluck('ulid', 'media_id');
        $favorited = InstanceFeatures::enabled('favorites')
            ? array_flip($this->favorites->favoritedIdsFor($viewer, 'media', $page->getCollection()->modelKeys())) : [];

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (Media $media): array => $this->presenter->media($media, $viewer, isset($favorited[$media->id]), libraryItemUlid: $saved[$media->id] ?? null, photoVersions: $versions[$media->id] ?? null)),
            'meta' => PaginationMeta::from($page),
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $media = $this->visibleMedia($request->user())->where('ulid', $ulid)->first();
        abort_unless($media instanceof Media, 404, 'Not found.');
        $favorited = InstanceFeatures::enabled('favorites')
            && $this->favorites->favoritedIdsFor($request->user(), 'media', [$media->id]) !== [];
        $libraryItemUlid = $request->user()->libraryItems()->where('media_id', $media->id)->value('ulid');

        $versions = $this->photoVersions->summaries($request->user(), collect([$media]));

        return response()->json(['success' => true, 'data' => $this->presenter->media($media, $request->user(), $favorited, resolveHls: true, libraryItemUlid: $libraryItemUlid, photoVersions: $versions[$media->id] ?? null)]);
    }

    public function variants(EventPhotoVariantsRequest $request, string $ulid): JsonResponse
    {
        $viewer = $request->user();
        $anchor = $this->photoVersions->visiblePhotos($viewer)->where('ulid', $ulid)->first();
        abort_unless($anchor instanceof Media, 404, 'Not found.');
        $query = $this->photoVersions->inGroup($this->photoVersions->visiblePhotos($viewer), $anchor);
        $preferred = $this->photoVersions->preferredOrder(clone $query)->value('media.ulid');
        $page = $this->photoVersions->preferredOrder($query)->with(['event.members:id', 'user', 'interests'])->paginate(24);
        $versions = $this->photoVersions->summaries($viewer, $page->getCollection());
        $saved = $viewer->libraryItems()->whereIn('media_id', $page->getCollection()->modelKeys())->pluck('ulid', 'media_id');
        $favorited = InstanceFeatures::enabled('favorites')
            ? array_flip($this->favorites->favoritedIdsFor($viewer, 'media', $page->getCollection()->modelKeys())) : [];

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (Media $media): array => $this->presenter->media($media, $viewer, isset($favorited[$media->id]), libraryItemUlid: $saved[$media->id] ?? null, photoVersions: $versions[$media->id] ?? null)),
            'meta' => PaginationMeta::from($page), 'preferred_ulid' => $preferred,
        ]);
    }

    public function upload(EventUploadRequest $request, Event $event, MediaUploadService $uploads): JsonResponse
    {
        $result = $uploads->createPendingUpload(
            $request->user(), MediaType::from($request->validated('type')),
            $request->validated('filename'), $request->validated('content_type'), $request->validated('title'), Audience::Everyone, [],
            wantsThumbnail: $request->boolean('has_thumbnail'), perceptualHash: $request->validated('perceptual_hash'),
            purpose: MediaPurpose::Event, discoverable: false, fileHash: $request->validated('file_hash'),
            expectedSizeBytes: (int) $request->validated('size'), thumbnailSizeBytes: $request->validated('thumbnail_size'), eventId: $event->id,
            photoMetadata: $request->validated('type') === 'photo' ? $request->validated('photo_metadata') : null,
            capturedAt: $request->validated('captured_at'),
        );
        $media = $result['media']->load(['event.members:id', 'user', 'interests']);

        return response()->json([
            'success' => true, 'data' => $this->presenter->media($media, $request->user()),
            'upload_url' => $result['upload_url'], 'upload_headers' => $result['upload_headers'],
            'thumbnail_upload_url' => $result['thumbnail_upload_url'], 'thumbnail_upload_headers' => $result['thumbnail_upload_headers'],
            'multipart' => [
                'enabled' => (bool) config('media.multipart.enabled', true) && $media->type->isVideo(),
                'threshold_bytes' => (int) config('media.multipart.threshold_bytes'), 'part_size_bytes' => (int) config('media.multipart.part_size_bytes'),
            ],
        ], 201);
    }

    public function download(Request $request, string $ulid, FileStorageService $storage): StreamedResponse
    {
        $media = $this->visibleMedia($request->user())->where('ulid', $ulid)->first();
        abort_unless($media instanceof Media && $media->isReady(), 404, 'Not found.');
        $stream = $storage->readStream($media->disk, $media->playbackObjectKey());
        abort_unless(is_resource($stream), 404, 'Not found.');
        $extension = $media->downloadExtension();

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', 'event-media-'.$media->ulid.'.'.$extension),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

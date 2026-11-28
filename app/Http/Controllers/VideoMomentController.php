<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Http\Requests\Media\StoreVideoMomentRequest;
use App\Http\Requests\Media\UpdateVideoMomentRequest;
use App\Http\Requests\Media\UseMomentAsCoverRequest;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoMoment;
use App\Services\Media\VideoMomentService;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Saved frames of a gallery video. Everyone who can watch the video may save
 * moments and see shared ones; images are served per viewer (see
 * VideoMomentService::imageUrl).
 */
class VideoMomentController extends Controller
{
    /** Page size for the moments list. */
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly VideoMomentService $moments,
        private readonly RestrictionGate $restrictions,
    ) {}

    public function index(Request $request, string $ulid): JsonResponse
    {
        $viewer = $this->viewer($request);
        $video = $this->findVideo($request, $ulid);
        $page = $this->moments->visibleTo($video, $viewer)
            ->with(['user.profilePicture', 'character.profilePicture'])
            ->orderBy('time_ms')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (VideoMoment $m): array => $this->moments->present($m, $video, $viewer))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(StoreVideoMomentRequest $request, string $ulid): JsonResponse
    {
        $viewer = $this->viewer($request);
        $video = $this->findVideo($request, $ulid);
        $result = $this->moments->create(
            $video,
            $viewer,
            (int) $request->validated('time_ms'),
            $request->caption(),
            (string) $request->validated('visibility'),
            (bool) $request->validated('wants_provisional', false) ? (int) $request->validated('capture_bytes') : null,
        );
        $result['moment']->load(['user.profilePicture', 'character.profilePicture']);

        return response()->json([
            'success' => true,
            'data' => $this->moments->present($result['moment'], $video, $viewer),
            'provisional_upload' => $result['provisional_upload'],
        ], 201);
    }

    public function completeProvisional(Request $request, VideoMoment $moment): JsonResponse
    {
        [$viewer, $video] = $this->authorizeMoment($request, $moment, authorOnly: true);
        $this->moments->completeProvisional($moment);

        return response()->json(['success' => true, 'data' => $this->moments->present($moment->refresh(), $video, $viewer)]);
    }

    public function update(UpdateVideoMomentRequest $request, VideoMoment $moment): JsonResponse
    {
        [$viewer, $video] = $this->authorizeMoment($request, $moment, authorOnly: true);
        $changes = $request->validated();
        if (array_key_exists('caption', $changes)) {
            $caption = is_string($changes['caption']) ? trim($changes['caption']) : '';
            $changes['caption'] = $caption === '' ? null : $caption;
        }
        $moment->forceFill($changes)->save();

        return response()->json(['success' => true, 'data' => $this->moments->present($moment, $video, $viewer)]);
    }

    public function retry(Request $request, VideoMoment $moment): JsonResponse
    {
        [$viewer, $video] = $this->authorizeMoment($request, $moment, authorOnly: true);
        $this->moments->retry($moment, $video);

        return response()->json(['success' => true, 'data' => $this->moments->present($moment, $video, $viewer)]);
    }

    public function destroy(Request $request, VideoMoment $moment): JsonResponse
    {
        [$viewer, $video] = $this->authorizeMoment($request, $moment, authorOnly: false);
        // Authors remove their own; uploaders curate moments on their video.
        abort_unless($moment->user_id === $viewer->id || $video->user_id === $viewer->id || $viewer->isAdmin(), 403, 'You cannot remove this moment.');
        $this->moments->delete($moment);

        return response()->json(['success' => true]);
    }

    public function image(Request $request, VideoMoment $moment, string $variant): RedirectResponse
    {
        [$viewer] = $this->authorizeMoment($request, $moment, authorOnly: false);
        $url = $this->moments->imageUrl($moment, $viewer, $variant === 'thumb');
        abort_if($url === null, 404, 'Not found.');

        return redirect()->away($url, 302)->header('Cache-Control', 'private, no-store');
    }

    public function useAsCover(UseMomentAsCoverRequest $request, string $ulid): JsonResponse
    {
        $viewer = $this->viewer($request);
        $video = $this->findVideo($request, $ulid);
        abort_unless(Gate::allows('update', $video) && $video->isApprovedContent(), 403, 'Only the uploader can change the cover.');
        $moment = $this->moments->visibleTo($video, $viewer)->where('ulid', (string) $request->validated('moment'))->first();
        abort_unless($moment instanceof VideoMoment, 404, 'Not found.');
        $this->moments->useAsCover($video, $moment);

        return response()->json(['success' => true]);
    }

    private function viewer(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 401);

        return $viewer;
    }

    private function findVideo(Request $request, string $ulid): Media
    {
        $video = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('ulid', $ulid)
            ->first();
        $this->authorizeOr404('view', $video);
        /** @var Media $video */
        abort_unless($video->type->isVideo(), 404, 'Not found.');
        $viewer = $this->viewer($request);
        if ($video->user_id !== $viewer->id && $this->restrictions->denies($viewer, RestrictionCapability::MediaView)) {
            abort(403, 'Your account is restricted from viewing media.');
        }

        return $video;
    }

    /**
     * A moment is reachable only through its video's access and the moments
     * visibility rules; anything else answers 404.
     *
     * @return array{0: User, 1: Media}
     */
    private function authorizeMoment(Request $request, VideoMoment $moment, bool $authorOnly): array
    {
        $viewer = $this->viewer($request);
        $video = $moment->media;
        abort_unless($video instanceof Media && $video->purpose === MediaPurpose::Gallery, 404, 'Not found.');
        $this->authorizeOr404('view', $video);
        if ($video->user_id !== $viewer->id && $this->restrictions->denies($viewer, RestrictionCapability::MediaView)) {
            abort(403, 'Your account is restricted from viewing media.');
        }
        abort_unless($this->moments->visibleTo($video, $viewer)->whereKey($moment->id)->exists(), 404, 'Not found.');
        abort_if($authorOnly && $moment->user_id !== $viewer->id, 403, 'Only the author can change this moment.');

        return [$viewer, $video];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Http\Requests\Media\UpdateVideoChaptersRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\HlsService;
use App\Services\Media\VideoChapterService;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A gallery video's chapters: replaced as a whole by its uploader, and served
 * to anyone who can watch it as a WebVTT chapters track.
 */
class VideoChapterController extends Controller
{
    public function __construct(
        private readonly VideoChapterService $chapters,
        private readonly HlsService $hls,
        private readonly RestrictionGate $restrictions,
    ) {}

    public function update(UpdateVideoChaptersRequest $request, string $ulid): JsonResponse
    {
        $media = $this->findViewable($request, $ulid);
        abort_unless(Gate::allows('editChapters', $media), 403, 'Only the uploader can edit chapters.');

        $chapters = $request->chapters();
        $this->hls->ensureVideoMetadata($media);
        $duration = $media->video_duration_ms;
        if ($duration !== null) {
            foreach ($chapters as $index => $chapter) {
                if ($chapter['start_ms'] >= $duration) {
                    throw ValidationException::withMessages([
                        "chapters.{$index}.start_ms" => 'A chapter starts after the end of the video.',
                    ]);
                }
            }
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $saved = $this->chapters->replace($media, $user, $chapters);

        return response()->json(['success' => true, 'data' => $this->chapters->present($saved)]);
    }

    public function vtt(Request $request, string $ulid): Response
    {
        $media = $this->findViewable($request, $ulid);
        abort_unless($media->type->isVideo(), 404, 'Not found.');

        return response($this->chapters->toWebVtt($media->chapters()->get(), $media->video_duration_ms), 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    private function findViewable(Request $request, string $ulid): Media
    {
        $media = Media::query()
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('ulid', $ulid)
            ->first();
        $this->authorizeOr404('view', $media);
        /** @var Media $media */
        $viewer = $request->user();
        if ($viewer instanceof User && $media->user_id !== $viewer->id
            && $this->restrictions->denies($viewer, RestrictionCapability::MediaView)) {
            abort(403, 'Your account is restricted from viewing media.');
        }

        return $media;
    }
}

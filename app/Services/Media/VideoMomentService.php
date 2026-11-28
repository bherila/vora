<?php

namespace App\Services\Media;

use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoMoment;
use App\Services\FileStorageService;
use App\Support\BlockGraph;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saved frames of videos: who sees them, which image each viewer gets, the
 * worker round trip, and cleanup. See docs/media/video-annotations.md.
 *
 * The safety rule: a provisional (browser-captured) image is only ever served
 * to the account that uploaded it and is never promoted; everyone else only
 * ever receives frames the transcoder extracted from approved output.
 */
class VideoMomentService
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly FrameRequestService $frames,
        private readonly HlsService $hls,
        private readonly MediaResponseService $responder,
    ) {}

    private function provisionalDisk(): string
    {
        return (string) config('media.thumbnail_disk');
    }

    /**
     * Moments of a video the viewer may see: shared ones and their own, from
     * authors who are active, not blocked either way, and whose persona (if
     * any) still exists.
     *
     * @return Builder<VideoMoment>
     */
    public function visibleTo(Media $video, User $viewer): Builder
    {
        $query = VideoMoment::query()
            ->where('video_moments.media_id', $video->id)
            ->where(fn (Builder $q) => $q->where('visibility', VideoMoment::VISIBILITY_SHARED)->orWhere('video_moments.user_id', $viewer->id))
            ->whereHas('user', fn (Builder $author) => $author->active())
            ->where(fn (Builder $q) => $q->whereNull('video_moments.character_id')->orWhereHas('character'));

        /** @var Builder<VideoMoment> $visible */
        $visible = BlockGraph::visibleTo($query, $viewer, 'video_moments.user_id', 'video_moments.character_id');

        return $visible;
    }

    /**
     * Save a moment. Returns it, plus a presigned upload of exactly
     * $captureBytes for the provisional capture when the frame is not ready
     * yet and a capture was offered.
     *
     * @return array{moment: VideoMoment, provisional_upload: ?array{url: string, headers: array<string, string>}}
     */
    public function create(Media $video, User $author, int $timeMs, ?string $caption, string $visibility, ?int $captureBytes): array
    {
        if (! $this->hls->ensureResolved($video) || $video->hls_content_id === null) {
            throw ValidationException::withMessages(['time_ms' => 'This video is not ready for moments yet.']);
        }
        $this->hls->ensureVideoMetadata($video);
        if ($video->video_duration_ms !== null && $timeMs >= $video->video_duration_ms) {
            throw ValidationException::withMessages(['time_ms' => 'That time is after the end of the video.']);
        }

        $moment = DB::transaction(function () use ($video, $author, $timeMs, $caption, $visibility): VideoMoment {
            // Serialize creates per video so the caps cannot be raced past.
            Media::query()->whereKey($video->id)->lockForUpdate()->first();
            $total = VideoMoment::query()->where('media_id', $video->id)->count();
            $mine = VideoMoment::query()->where('media_id', $video->id)->where('user_id', $author->id)->count();
            if ($mine >= (int) config('media.moments.max_per_user_per_video') || $total >= (int) config('media.moments.max_per_video')) {
                throw ValidationException::withMessages(['time_ms' => 'This video has reached its limit of saved moments.']);
            }

            return VideoMoment::query()->create([
                'media_id' => $video->id,
                'user_id' => $author->id,
                // As with comments, only the uploader's own moments on a
                // persona's video appear as that persona.
                'character_id' => $author->id === $video->user_id ? $video->character_id : null,
                'content_id' => (string) $video->hls_content_id,
                'time_ms' => $timeMs,
                'caption' => $caption,
                'visibility' => $visibility,
                'frame_status' => VideoMoment::FRAME_PENDING,
            ]);
        });

        $status = $this->frames->status($moment->content_id, $moment->time_ms);
        if ($status === FrameRequestService::STATUS_READY) {
            $moment->forceFill(['frame_status' => VideoMoment::FRAME_READY, 'frame_checked_at' => now()])->save();

            return ['moment' => $moment, 'provisional_upload' => null];
        }
        // Record the attempt before dispatching it, so an error the worker
        // writes while the wake call is still in flight is not mistaken for
        // an earlier attempt's.
        $moment->forceFill(['frame_requested_at' => now()])->save();
        $this->frames->request($video, $moment->content_id, $moment->time_ms);

        $upload = null;
        if ($captureBytes !== null) {
            $key = 'moments/provisional/'.$author->id.'/'.$moment->ulid.'.jpg';
            $upload = $this->storage->getSignedUploadUrl($this->provisionalDisk(), $key, 'image/jpeg', $captureBytes, 15);
            $moment->forceFill(['provisional_key' => $key, 'provisional_status' => VideoMoment::PROVISIONAL_AWAITING])->save();
        }

        return ['moment' => $moment, 'provisional_upload' => $upload];
    }

    /**
     * Accept the author's uploaded capture after checking it is a bounded
     * JPEG or WebP image. A capture that fails the check is deleted.
     */
    public function completeProvisional(VideoMoment $moment): void
    {
        if ($moment->provisional_key === null || $moment->provisional_status !== VideoMoment::PROVISIONAL_AWAITING) {
            throw ValidationException::withMessages(['moment' => 'There is no capture waiting for this moment.']);
        }
        $disk = $this->provisionalDisk();
        $size = $this->storage->getFileSize($disk, $moment->provisional_key);
        $bytes = $size !== null && $size > 0 && $size <= (int) config('media.moments.provisional_max_bytes')
            ? $this->storage->get($disk, $moment->provisional_key)
            : null;
        $info = $bytes === null ? false : @getimagesizefromstring($bytes);
        $max = (int) config('media.moments.provisional_max_dimension');
        $valid = is_array($info)
            && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            && $info[0] > 0 && $info[1] > 0 && $info[0] <= $max && $info[1] <= $max;

        if (! $valid) {
            $this->deleteProvisional($moment);
            throw ValidationException::withMessages(['moment' => 'The capture must be a JPEG or WebP image of at most 1.5 MB.']);
        }
        $moment->forceFill(['provisional_status' => VideoMoment::PROVISIONAL_UPLOADED, 'provisional_size_bytes' => $size])->save();
    }

    /**
     * The URL of the image this viewer may see for a moment, or null:
     * the authoritative frame for everyone once it exists, otherwise the
     * provisional capture for its author only.
     */
    public function imageUrl(VideoMoment $moment, User $viewer, bool $thumbnail): ?string
    {
        if ($moment->frameReady()) {
            return $this->frames->signedFrameUrl($moment->content_id, $moment->time_ms, $thumbnail);
        }
        if ($moment->user_id === $viewer->id && $moment->provisional_key !== null
            && $moment->provisional_status === VideoMoment::PROVISIONAL_UPLOADED) {
            return $this->storage->getSignedViewUrl($this->provisionalDisk(), $moment->provisional_key, 10, 'image/jpeg');
        }

        return null;
    }

    /**
     * The one payload shape for a moment. Image URLs are app routes; the
     * route decides per viewer which image (if any) to serve.
     *
     * @return array<string, mixed>
     */
    public function present(VideoMoment $moment, Media $video, User $viewer): array
    {
        $isMine = $moment->user_id === $viewer->id;
        $hasImage = $moment->frameReady()
            || ($isMine && $moment->provisional_status === VideoMoment::PROVISIONAL_UPLOADED);

        return [
            'ulid' => $moment->ulid,
            'time_ms' => $moment->time_ms,
            'caption' => $moment->caption,
            'visibility' => $isMine ? $moment->visibility : VideoMoment::VISIBILITY_SHARED,
            'frame_status' => $moment->frame_status,
            'is_mine' => $isMine,
            'can_edit' => $isMine,
            'can_delete' => $isMine || $video->user_id === $viewer->id || $viewer->isAdmin(),
            'can_use_as_cover' => $moment->frameReady() && ($video->user_id === $viewer->id) && $video->isApprovedContent(),
            'author' => $this->author($moment, $video),
            'image' => $hasImage ? [
                'full_url' => route('moments.image', ['moment' => $moment->ulid, 'variant' => 'full'], false),
                'thumb_url' => route('moments.image', ['moment' => $moment->ulid, 'variant' => 'thumb'], false),
                'provisional' => ! $moment->frameReady(),
            ] : null,
            'created_at' => $moment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function author(VideoMoment $moment, Media $video): ?array
    {
        $character = $moment->character;
        if ($moment->character_id !== null && $character instanceof Character && ! $character->is_linked) {
            return [
                'id' => null,
                'display_name' => $character->display_name,
                'avatar_url' => UserPresenter::pictureUrl($character->profilePicture, $this->responder, null),
            ];
        }

        return UserPresenter::identity($moment->user, $this->responder, null);
    }

    /**
     * Check pending frames with the worker, a batch at a time. Moments of the
     * same frame share one answer. Returns how many moments changed.
     */
    public function syncPendingFrames(int $limit = 200): int
    {
        $cutoff = now()->subDays((int) config('media.moments.frame_wait_days'));
        $pending = VideoMoment::query()
            ->where('frame_status', VideoMoment::FRAME_PENDING)
            ->with('media')
            ->orderBy('frame_checked_at')
            ->limit($limit)
            ->get()
            ->groupBy(fn (VideoMoment $m): string => $m->content_id.'@'.$m->time_ms);

        $changed = 0;
        foreach ($pending as $group) {
            /** @var VideoMoment $first */
            $first = $group->first();
            // Judge an error against the group's latest request: a retry by
            // any of them supersedes an earlier failure for all.
            $latestRequest = $group->max(fn (VideoMoment $m) => $m->frame_requested_at ?? $m->created_at);
            $status = $this->frames->status($first->content_id, $first->time_ms, $latestRequest);
            $expired = $status === FrameRequestService::STATUS_PENDING && $group->every(fn (VideoMoment $m): bool => ($m->frame_requested_at ?? $m->created_at)?->lt($cutoff) ?? false);
            foreach ($group as $moment) {
                if ($status === FrameRequestService::STATUS_READY) {
                    $this->deleteProvisional($moment);
                    $moment->forceFill(['frame_status' => VideoMoment::FRAME_READY, 'frame_checked_at' => now()])->save();
                    $changed++;
                } elseif ($status === FrameRequestService::STATUS_FAILED || $expired) {
                    $moment->forceFill(['frame_status' => VideoMoment::FRAME_FAILED, 'frame_checked_at' => now()])->save();
                    $changed++;
                } else {
                    $moment->forceFill(['frame_checked_at' => now()])->save();
                }
            }
            if ($status !== FrameRequestService::STATUS_PENDING && $first->media instanceof Media) {
                $this->frames->clearRequest($first->media, $first->content_id, $first->time_ms);
            }
        }

        return $changed;
    }

    /** Ask the worker again for a moment whose frame failed (author only). */
    public function retry(VideoMoment $moment, Media $video): void
    {
        if ($moment->frame_status !== VideoMoment::FRAME_FAILED) {
            return;
        }
        $moment->forceFill(['frame_status' => VideoMoment::FRAME_PENDING, 'frame_requested_at' => now(), 'frame_checked_at' => null])->save();
        $this->frames->request($video, $moment->content_id, $moment->time_ms);
    }

    /**
     * Make a ready moment's frame the video's poster. The bytes come from
     * approved output, so no re-review is needed.
     */
    public function useAsCover(Media $video, VideoMoment $moment): void
    {
        $bytes = $moment->frameReady() ? $this->frames->frameBytes($moment->content_id, $moment->time_ms) : null;
        if ($bytes === null || $bytes === '') {
            throw ValidationException::withMessages(['moment' => 'That frame is not ready yet.']);
        }
        $disk = (string) config('media.thumbnail_disk');
        $stem = 'posters/'.$video->user_id.'/'.$video->ulid.'-'.Str::lower((string) Str::ulid());
        // The source poster and its reviewed copy are separate objects, as
        // moderation expects: rejection deletes the reviewed copy, and a later
        // approval copies the source again.
        $key = $stem.'.jpg';
        $reviewedKey = $stem.'-reviewed.jpg';
        if (! $this->storage->putString($disk, $key, $bytes, 'image/jpeg')) {
            throw ValidationException::withMessages(['moment' => 'The cover could not be saved. Try again.']);
        }
        if (! $this->storage->putString($disk, $reviewedKey, $bytes, 'image/jpeg')) {
            $this->storage->deleteFile($disk, $key);

            throw ValidationException::withMessages(['moment' => 'The cover could not be saved. Try again.']);
        }

        $old = array_unique(array_filter([$video->thumbnail_key, $video->reviewed_thumbnail_key]));
        $video->forceFill(['thumbnail_key' => $key, 'reviewed_thumbnail_key' => $reviewedKey])->save();
        foreach ($old as $previous) {
            $this->storage->deleteFile($disk, $previous);
        }
    }

    public function delete(VideoMoment $moment): void
    {
        $this->deleteProvisional($moment);
        $moment->delete();
    }

    /** Remove every moment of a video and its captures (permanent removal). */
    public function purgeForMedia(Media $video): void
    {
        VideoMoment::query()->where('media_id', $video->id)->eachById(function (VideoMoment $moment) use ($video): void {
            $this->deleteProvisional($moment);
            if ($moment->frame_status === VideoMoment::FRAME_PENDING) {
                $this->frames->clearRequest($video, $moment->content_id, $moment->time_ms);
            }
            $moment->delete();
        });
    }

    /**
     * Delete every provisional capture of a video at once. Used on rejection:
     * a capture is a copy of the video's content outside the HLS output, so a
     * takedown would otherwise never reach it.
     */
    public function deleteProvisionalsForMedia(Media $video): void
    {
        VideoMoment::query()->where('media_id', $video->id)->whereNotNull('provisional_key')
            ->eachById(fn (VideoMoment $moment) => $this->deleteProvisional($moment));
    }

    /** Remove an account's own moments and captures (account deletion). */
    public function purgeForUser(User $user): void
    {
        VideoMoment::query()->where('user_id', $user->id)->eachById(fn (VideoMoment $moment) => $this->delete($moment));
    }

    /**
     * Drop captures whose upload never completed.
     */
    public function pruneAbandonedProvisionals(\DateTimeInterface $before): int
    {
        $count = 0;
        VideoMoment::query()->where('provisional_status', VideoMoment::PROVISIONAL_AWAITING)->where('created_at', '<', $before)
            ->eachById(function (VideoMoment $moment) use (&$count): void {
                $this->deleteProvisional($moment);
                $count++;
            });

        return $count;
    }

    private function deleteProvisional(VideoMoment $moment): void
    {
        if ($moment->provisional_key === null) {
            return;
        }
        $this->storage->deleteFile($this->provisionalDisk(), $moment->provisional_key);
        $moment->forceFill(['provisional_key' => null, 'provisional_status' => null, 'provisional_size_bytes' => null]);
        if ($moment->exists) {
            $moment->save();
        }
    }
}

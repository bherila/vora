<?php

namespace App\Services\Events;

use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\RestrictionCapability;
use App\Models\Media;
use App\Models\User;
use App\Services\Moderation\RestrictionGate;
use App\Support\PerceptualHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Likely visual versions only: no original, metadata or Saved reference is replaced. */
class EventPhotoVersions
{
    public const NEAR_CANDIDATE_LIMIT = 512;

    private const MAX_DISTANCE = 4;

    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif'];

    public static function supportsMime(string $mime): bool
    {
        return in_array($mime, self::MIME_TYPES, true);
    }

    public static function normalizeHash(?string $hash): ?string
    {
        $bytes = $hash === null ? false : base64_decode($hash, true);

        return $bytes !== false && strlen($bytes) === 32 ? base64_encode($bytes) : null;
    }

    /** @param array<string, mixed>|null $metadata @return array<string, mixed> */
    public static function metadataAttributes(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }
        $width = isset($metadata['width'], $metadata['height']) ? (int) $metadata['width'] : null;
        $height = $width === null ? null : (int) $metadata['height'];
        $flags = [
            'has_gps' => (bool) ($metadata['has_gps'] ?? false),
            'has_capture_time' => (bool) ($metadata['has_capture_time'] ?? false),
            'has_camera' => (bool) ($metadata['has_camera'] ?? false),
        ];

        return [
            'photo_width' => $width, 'photo_height' => $height,
            'photo_pixel_area' => $width === null ? 0 : $width * $height,
            'photo_metadata' => array_intersect(array_keys($metadata), array_keys($flags)) === [] ? null : $flags,
            'photo_metadata_score' => ($flags['has_gps'] ? 4 : 0) + ($flags['has_capture_time'] ? 2 : 0) + ($flags['has_camera'] ? 1 : 0),
        ];
    }

    /** Called while account, Event and the newly completed media row are locked. */
    public function groupCompletedPhoto(Media $media): void
    {
        $hash = self::normalizeHash($media->perceptual_hash);
        if ($media->purpose !== MediaPurpose::Event || $media->type !== MediaType::Photo
            || ! self::supportsMime($media->mime_type)
            || $media->event_id === null || ! $media->isReady() || $hash === null || $media->photo_group_ulid !== null) {
            return;
        }
        $query = Media::query()->where('purpose', MediaPurpose::Event)->where('type', MediaType::Photo)
            ->whereIn('mime_type', self::MIME_TYPES)
            ->where('event_id', $media->event_id)->where('upload_status', 'ready')->whereKeyNot($media->id)
            ->whereNotNull('perceptual_hash');
        // The indexed exact lookup is not restricted by the recent near-match
        // window, so an older exact visual hash remains discoverable.
        $exact = (clone $query)->where('perceptual_hash', $hash)
            // Base64 is case-sensitive; MariaDB's default text collation is
            // not. Keep the indexed lookup and verify exact encoded bytes.
            ->whereRaw('HEX(perceptual_hash) = ?', [strtoupper(bin2hex($hash))]);
        $candidate = (clone $exact)->whereNotNull('photo_group_ulid')->orderBy('id')->first(['id', 'ulid', 'photo_group_ulid'])
            ?? $exact->orderBy('id')->first(['id', 'ulid', 'photo_group_ulid']);
        if ($candidate === null) {
            $distance = self::MAX_DISTANCE + 1;
            foreach ($query->orderByDesc('id')->limit(self::NEAR_CANDIDATE_LIMIT)->get(['id', 'ulid', 'photo_group_ulid', 'perceptual_hash']) as $possible) {
                $normalized = self::normalizeHash($possible->perceptual_hash);
                $bits = $normalized === null ? null : PerceptualHash::hammingDistance($hash, $normalized);
                if ($bits !== null && $bits <= self::MAX_DISTANCE
                    && ($candidate === null || $bits < $distance || ($bits === $distance && $possible->id < $candidate->id))) {
                    $candidate = $possible;
                    $distance = $bits;
                }
            }
        }
        if ($candidate !== null) {
            // An unmatched root keeps NULL and implicitly owns its ULID. Only
            // the new locked row changes; older media locks and Saves stay intact.
            $media->photo_group_ulid = $candidate->photo_group_ulid ?? $candidate->ulid;
            $media->saveQuietly();
        }
    }

    /** @param Builder<Media> $query @return Builder<Media> */
    public function collapse(Builder $query): Builder
    {
        $mimeTypes = "'".implode("','", self::MIME_TYPES)."'";
        $published = "media.type = 'photo' AND media.mime_type IN (".$mimeTypes.") AND media.upload_status = 'ready' AND media.moderation_status = 'approved'";
        $kind = 'CASE WHEN '.$published.' THEN 1 ELSE 0 END';
        $key = 'CASE WHEN '.$published.' THEN COALESCE(media.photo_group_ulid, media.ulid) ELSE media.ulid END';
        $ranked = (clone $query)->reorder()->setEagerLoads([])->select('media.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY media.event_id, '.$kind.', '.$key.' ORDER BY '.$this->rankOrder().') AS version_rank');
        $ids = DB::query()->fromSub($ranked->toBase(), 'ranked_photos')->select('id')->where('version_rank', 1);

        // Select only actual Media columns, never dirty synthetic attributes
        // that a playback-status refresh might accidentally persist.
        return Media::query()->whereIn('media.id', $ids)->setEagerLoads($query->getEagerLoads());
    }

    /** @return Builder<Media> */
    public function visiblePhotos(User $viewer): Builder
    {
        $query = Media::query()->where('purpose', MediaPurpose::Event)->where('type', MediaType::Photo)
            ->whereIn('mime_type', self::MIME_TYPES)
            ->where('upload_status', 'ready')->where('moderation_status', 'approved')->viewableBy($viewer)
            ->whereHas('user', fn (Builder $owner) => $owner->active()->whereNotNull('approved_at'));
        if (app(RestrictionGate::class)->denies($viewer, RestrictionCapability::MediaView)) {
            $query->where('user_id', $viewer->id);
        }

        return $query;
    }

    /** @param Builder<Media> $query @return Builder<Media> */
    public function inGroup(Builder $query, Media $anchor): Builder
    {
        $root = $anchor->photo_group_ulid ?? $anchor->ulid;

        return $query->where('event_id', $anchor->event_id)
            ->where(fn (Builder $group) => $group->where('photo_group_ulid', $root)
                ->orWhere(fn (Builder $original) => $original->whereNull('photo_group_ulid')->where('ulid', $root)));
    }

    /** @param Builder<Media> $query @return Builder<Media> */
    public function preferredOrder(Builder $query): Builder
    {
        return $query->orderByDesc('photo_pixel_area')->orderByDesc('photo_metadata_score')->orderBy('media.id');
    }

    private function rankOrder(): string
    {
        return 'media.photo_pixel_area DESC, media.photo_metadata_score DESC, media.id ASC';
    }

    /** @param Collection<int, Media> $media @return array<int, array<string, mixed>> */
    public function summaries(User $viewer, Collection $media): array
    {
        $photos = $media->filter(fn (Media $item): bool => $item->type === MediaType::Photo && self::supportsMime($item->mime_type) && $item->isReady() && $item->isApprovedContent());
        if ($photos->isEmpty()) {
            return [];
        }
        $query = $this->visiblePhotos($viewer)->where(function (Builder $groups) use ($photos): void {
            foreach ($photos as $photo) {
                $groups->orWhere(fn (Builder $group) => $this->inGroup($group, $photo));
            }
        });
        $partition = 'media.event_id, COALESCE(media.photo_group_ulid, media.ulid)';
        $ranked = $query->select(['media.event_id', 'media.ulid'])->selectRaw('COALESCE(media.photo_group_ulid, media.ulid) AS photo_root')
            ->selectRaw('COUNT(*) OVER (PARTITION BY '.$partition.') AS visible_count')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$partition.' ORDER BY '.$this->rankOrder().') AS version_rank');
        $groups = DB::query()->fromSub($ranked->toBase(), 'visible_versions')->where('version_rank', 1)->get()->keyBy(fn (object $row): string => $row->event_id.':'.$row->photo_root);
        $result = [];
        foreach ($photos as $photo) {
            $group = $groups->get($photo->event_id.':'.($photo->photo_group_ulid ?? $photo->ulid));
            if ($group !== null && (int) $group->visible_count > 1) {
                $result[$photo->id] = ['visible_count' => (int) $group->visible_count, 'preferred_ulid' => $group->ulid, 'is_preferred' => $group->ulid === $photo->ulid];
            }
        }

        return $result;
    }
}

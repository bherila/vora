<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use App\Support\PerceptualHash;

/**
 * Duplicate detection for uploads. Exact (byte-identical) duplicates are blocked
 * up front; near-duplicates (perceptually similar photos, or videos the
 * transcoder resolved to the same content id) are flagged for admin review but
 * never block the upload. All matching is scoped to the same owner — we never
 * reveal whether another user has uploaded a given file.
 */
class MediaDuplicateService
{
    /**
     * Max bit difference between two 256-bit perceptual hashes for the photos to
     * be treated as near-duplicates (~4% of the bits).
     */
    private const PERCEPTUAL_THRESHOLD = 10;

    /**
     * Max bit difference between two 256-bit PDQ hashes for a per-owner match.
     * 31 is the canonical PDQ "same image" distance (Facebook/ThreatExchange);
     * the per-owner flag tolerates this looser bound because it never blocks and
     * only surfaces for review. The global admin search will want a tighter
     * bound for precision.
     */
    private const PDQ_THRESHOLD = 31;

    /**
     * The owner's existing, completed, byte-identical upload, if any. Used to
     * reject an exact re-upload before a new pending row is created.
     */
    public function findExactDuplicate(User $user, ?string $fileHash, ?int $excludeId = null): ?Media
    {
        if ($fileHash === null || $fileHash === '') {
            return null;
        }

        return Media::query()
            ->where('user_id', $user->id)
            ->where('file_hash', $fileHash)
            ->where('upload_status', 'ready')
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->first();
    }

    /**
     * Flag a freshly stored photo as a likely duplicate of the owner's nearest
     * existing photo within the perceptual threshold. No-op for videos, when the
     * hash is missing, or when nothing is close enough.
     */
    public function flagPerceptualDuplicate(Media $media): void
    {
        if ($media->type !== MediaType::Photo || $media->perceptual_hash === null || $media->duplicate_of_media_id !== null) {
            return;
        }

        $candidates = Media::query()
            ->where('user_id', $media->user_id)
            ->where('type', MediaType::Photo->value)
            ->where('upload_status', 'ready')
            ->whereNotNull('perceptual_hash')
            ->whereKeyNot($media->id)
            ->get(['id', 'perceptual_hash']);

        $bestId = null;
        $bestDistance = null;
        foreach ($candidates as $candidate) {
            $distance = PerceptualHash::hammingDistance($media->perceptual_hash, $candidate->perceptual_hash);
            if ($distance === null || $distance > self::PERCEPTUAL_THRESHOLD) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestId = $candidate->id;
            }
        }

        if ($bestId !== null) {
            $media->forceFill(['duplicate_of_media_id' => $bestId])->save();
        }
    }

    /**
     * Flag a freshly hashed photo as a likely duplicate of the owner's nearest
     * existing photo within the PDQ threshold. The PDQ hash (computed by the
     * out-of-band image worker) is more robust than the client blockhash, so it
     * catches re-uploads the perceptual_hash pass misses. No-op for videos, when
     * the PDQ hash is missing, or when the row is already flagged. Stays scoped
     * to the owner — the global cross-account search is admin-only and lives
     * elsewhere.
     */
    public function flagPdqDuplicate(Media $media): void
    {
        if ($media->type !== MediaType::Photo || $media->pdq_hash === null) {
            return;
        }

        $candidates = Media::query()
            ->where('user_id', $media->user_id)
            ->where('type', MediaType::Photo->value)
            ->where('upload_status', 'ready')
            ->whereNotNull('pdq_hash')
            ->whereKeyNot($media->id)
            ->get(['id', 'pdq_hash', 'duplicate_of_media_id']);

        $best = null;
        $bestDistance = null;
        foreach ($candidates as $candidate) {
            $distance = PerceptualHash::hammingDistanceHex($media->pdq_hash, $candidate->pdq_hash);
            if ($distance === null || $distance > self::PDQ_THRESHOLD) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if ($best === null) {
            return;
        }

        // The worker resolves hashes asynchronously and out of order, so this can
        // run with $media being either the older or the newer of the pair. Always
        // point the newer (higher id) at the older original, so the flag is stable
        // regardless of which hash was resolved first — never flag the original as
        // a duplicate of a later upload.
        [$newer, $olderId] = $media->id > $best->id ? [$media, $best->id] : [$best, $media->id];

        if ($newer->duplicate_of_media_id === null) {
            $newer->forceFill(['duplicate_of_media_id' => $olderId])->save();
        }
    }

    /**
     * Flag a video as a likely duplicate of the owner's earlier video that the
     * transcoder resolved to the same content id. Called once the content id is
     * known (the transcoder's content-aware hash). No-op if already flagged.
     */
    public function flagContentDuplicate(Media $video): void
    {
        if ($video->hls_content_id === null || $video->duplicate_of_media_id !== null) {
            return;
        }

        $match = Media::query()
            ->where('user_id', $video->user_id)
            ->where('hls_content_id', $video->hls_content_id)
            ->whereKeyNot($video->id)
            ->orderBy('id')
            ->first(['id']);

        if ($match !== null) {
            $video->forceFill(['duplicate_of_media_id' => $match->id])->save();
        }
    }
}

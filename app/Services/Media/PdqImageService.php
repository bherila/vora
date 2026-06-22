<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Services\FileStorageService;

/**
 * Bridges the app to the out-of-band image-hashing worker's results, mirroring
 * {@see HlsService} for video. For every photo object the worker writes a
 * mapping at `image-mappings/<object-key>.json` containing a PDQ perceptual
 * hash (256-bit, 64 hex chars) plus its quality score. The app reads that
 * mapping, caches the hash on the row, and flags per-owner near-duplicates.
 *
 * The worker computes hashes only — all duplicate decisioning stays in the app.
 * Approved media resolve from the immutable reviewed copy, not the
 * client-overwritable upload key.
 */
class PdqImageService
{
    /** Re-check interval for a not-yet-hashed photo's mapping. */
    private const RECHECK_AFTER_MINUTES = 2;

    public function __construct(
        private readonly FileStorageService $storage,
        private readonly MediaDuplicateService $duplicates,
    ) {}

    private function disk(): string
    {
        return (string) config('media.pdq_disk', 'hls');
    }

    /**
     * Resolve whether a PDQ hash exists for this photo, caching it on the row.
     * Cheap and idempotent: returns immediately once known, otherwise looks up
     * the mapping at most once per RECHECK_AFTER_MINUTES. Flags a per-owner
     * near-duplicate the first time a hash is resolved.
     */
    public function ensureResolved(Media $photo): bool
    {
        if ($photo->type !== MediaType::Photo) {
            return false;
        }

        if ($photo->pdq_hash !== null) {
            return true;
        }

        if (! $this->isRecheckDue($photo)) {
            return false;
        }

        $hash = $this->lookupPdqHash($photo->playbackObjectKey());

        $photo->pdq_checked_at = now();
        if ($hash !== null) {
            $photo->pdq_hash = $hash;
        }
        $photo->saveQuietly();

        if ($hash !== null) {
            $this->duplicates->flagPdqDuplicate($photo);
        }

        return $hash !== null;
    }

    /**
     * Permanent-delete cleanup: the worker's mapping is row-private and can
     * always go. There is no content-addressed output tree to GC (the worker
     * only stores a hash), so unlike HLS there is nothing shared to reference.
     */
    public function deleteMapping(Media $photo): void
    {
        if ($photo->type !== MediaType::Photo) {
            return;
        }

        $disk = $this->disk();
        $keys = array_values(array_unique(array_filter([
            $photo->object_key,
            $photo->reviewed_object_key,
        ])));

        foreach ($keys as $sourceKey) {
            $this->storage->deleteFile($disk, 'image-mappings/'.$sourceKey.'.json');
        }
    }

    private function isRecheckDue(Media $photo): bool
    {
        return $photo->pdq_checked_at === null
            || $photo->pdq_checked_at->lt(now()->subMinutes(self::RECHECK_AFTER_MINUTES));
    }

    /**
     * Read the worker's mapping and return a normalized 64-char lowercase hex
     * PDQ hash, or null when the mapping is absent or malformed.
     */
    private function lookupPdqHash(string $sourceKey): ?string
    {
        $raw = $this->storage->get($this->disk(), 'image-mappings/'.$sourceKey.'.json');
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $hash = is_array($decoded) ? ($decoded['pdqHash'] ?? null) : null;

        if (! is_string($hash)) {
            return null;
        }

        $hash = strtolower(trim($hash));

        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : null;
    }
}

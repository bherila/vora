<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccountStorageUsage
{
    public function __construct(private readonly AccountStorageLimit $limits) {}

    /**
     * Logical original-file usage, counted once per account and stored media row.
     * Sharing alone consumes no recipient storage. Derivatives are excluded;
     * unfinished uploads reserve their declared size. Unknown sizes are reported.
     *
     * @return array<string, int|string|bool|null>
     */
    public function forUser(User $user): array
    {
        $owned = $user->media()->withTrashed()->where('upload_status', 'ready')
            ->select('media.id', 'media.size_bytes')->selectRaw("'uploaded' AS source")
            ->selectRaw('CASE WHEN media.deleted_at IS NULL OR EXISTS (
                SELECT 1 FROM library_items WHERE library_items.media_id = media.id AND library_items.user_id = ?
            ) THEN 1 ELSE 0 END AS active', [$user->id]);
        $saved = Media::withTrashed()->where('upload_status', 'ready')
            ->where(fn (Builder $query) => $query->where('user_id', '!=', $user->id)->orWhereNull('user_id'))
            ->whereHas('libraryItems', fn (Builder $items) => $items->where('user_id', $user->id))
            ->select('media.id', 'media.size_bytes')
            ->selectRaw("CASE WHEN EXISTS (
                SELECT 1 FROM library_items WHERE library_items.media_id = media.id
                AND library_items.user_id = ? AND library_items.source = 'upload'
            ) THEN 'uploaded' ELSE 'saved' END AS source, 1 AS active", [$user->id]);
        $totals = DB::query()->fromSub($owned->toBase()->unionAll($saved->toBase()), 'account_files')
            ->selectRaw('COALESCE(SUM(CASE WHEN active = 1 THEN size_bytes ELSE 0 END), 0) AS active_bytes')
            ->selectRaw("COALESCE(SUM(CASE WHEN active = 1 AND source = 'uploaded' THEN size_bytes ELSE 0 END), 0) AS uploaded_bytes")
            ->selectRaw("COALESCE(SUM(CASE WHEN source = 'saved' THEN size_bytes ELSE 0 END), 0) AS saved_bytes")
            ->selectRaw('COALESCE(SUM(CASE WHEN active = 0 THEN size_bytes ELSE 0 END), 0) AS retained_bytes')
            ->selectRaw('COUNT(CASE WHEN active = 1 THEN 1 END) AS active_file_count')
            ->selectRaw('COUNT(CASE WHEN active = 0 THEN 1 END) AS retained_file_count')
            ->selectRaw('COUNT(CASE WHEN size_bytes IS NULL THEN 1 END) AS unknown_size_file_count')
            ->selectRaw('COUNT(CASE WHEN active = 1 AND size_bytes IS NULL THEN 1 END) AS active_unknown_size_file_count')->first();

        $activeBytes = (int) $totals->active_bytes;
        $retainedBytes = (int) $totals->retained_bytes;

        $reservedBytes = (int) $user->media()->where('upload_status', 'pending')->sum('multipart_expected_size_bytes');
        $limit = $this->limits->forUser($user);
        $usedBytes = $activeBytes + $reservedBytes;
        $unknown = (int) $totals->active_unknown_size_file_count;
        $warning = $limit === null ? null : ($unknown > 0 ? 'unknown_size'
            : ($usedBytes >= $limit ? 'limit_reached' : ($usedBytes >= $limit * 0.9 ? 'near_limit' : null)));

        return [
            'active_bytes' => $activeBytes,
            'uploaded_bytes' => (int) $totals->uploaded_bytes,
            'saved_bytes' => (int) $totals->saved_bytes,
            'retained_bytes' => $retainedBytes,
            'total_bytes' => $activeBytes + $retainedBytes,
            'active_file_count' => (int) $totals->active_file_count,
            'retained_file_count' => (int) $totals->retained_file_count,
            'unknown_size_file_count' => (int) $totals->unknown_size_file_count,
            'active_unknown_size_file_count' => $unknown,
            'limit_bytes' => $limit,
            'reserved_bytes' => $reservedBytes,
            'used_bytes' => $usedBytes,
            'remaining_bytes' => $limit === null ? null : max(0, $limit - $usedBytes),
            'over_limit' => $limit !== null && ($usedBytes > $limit || $unknown > 0),
            'warning' => $warning,
        ];
    }
}

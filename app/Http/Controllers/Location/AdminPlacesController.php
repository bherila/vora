<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\AdminPlacesImportRequest;
use App\Http\Requests\Location\AdminPlacesSettingsRequest;
use App\Models\Place;
use App\Services\Location\PlaceImporter;
use App\Services\Location\PlaceImportOptions;
use App\Services\Location\PlaceImportRequests;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → City list: the GeoNames dataset, imports and their outcome. Imports
 * are queued here and run by the scheduler (`places:run-requested`).
 */
class AdminPlacesController extends Controller
{
    public function __construct(
        private readonly PlaceImportRequests $requests,
        private readonly SettingsService $settings,
    ) {}

    public function page(): View
    {
        return view('location.admin-places', ['initialData' => ['adminPlaces' => $this->payload()]]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload()]);
    }

    public function updateSettings(AdminPlacesSettingsRequest $request): JsonResponse
    {
        $this->requests->updateSettings((string) $request->validated('dataset'), $request->boolean('auto_refresh'));

        return response()->json(['success' => true, 'message' => 'City list settings saved.', 'data' => $this->payload()]);
    }

    public function import(AdminPlacesImportRequest $request): JsonResponse
    {
        $source = (string) $request->validated('source');
        $dir = null;
        if ($source === 'upload') {
            $dir = $this->requests->newUploadDir();
            $cities = $request->file('cities');
            $cities?->move($dir, strtolower((string) $cities->getClientOriginalExtension()) === 'zip' ? 'cities.zip' : 'cities.txt');
            $request->file('admin1')?->move($dir, 'admin1CodesASCII.txt');
            $request->file('countries')?->move($dir, 'countryInfo.txt');
        } elseif ($source === 'preview') {
            // Apply what the last preview read: its uploaded files, or a fresh
            // download when the preview downloaded.
            $status = $this->requests->status();
            if (($status['state'] ?? null) !== 'succeeded' || ($status['dry_run'] ?? false) !== true) {
                return response()->json(['success' => false, 'message' => 'There is no preview to apply.'], 422);
            }
            $dir = $this->requests->previewUploadDir();
            if ($dir === null && ($status['upload_dir'] ?? null) !== null) {
                return response()->json(['success' => false, 'message' => 'The previewed files are no longer available. Upload them again.'], 422);
            }
        }

        $replace = $request->validated('mode') === PlaceImportOptions::MODE_REPLACE;
        $options = [
            'mode' => (string) $request->validated('mode'),
            'rehome' => $replace && $request->boolean('rehome'),
            'dry_run' => $request->boolean('dry_run'),
            'upload_dir' => $dir,
            'source' => 'admin',
        ];
        if ($source === 'preview') {
            $options['dataset'] = (string) ($this->requests->status()['dataset'] ?? '');
        }
        $this->requests->request($options, $request->user());

        return response()->json([
            'success' => true,
            'message' => $request->boolean('dry_run')
                ? 'Preview queued. It starts within a minute.'
                : 'Import queued. It starts within a minute.',
            'data' => $this->payload(),
        ], 202);
    }

    public function cancel(): JsonResponse
    {
        $this->requests->cancel();

        return response()->json(['success' => true, 'message' => 'Queued import cancelled.', 'data' => $this->payload()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $batch = $this->settings->getInt(PlaceImporter::CURRENT_BATCH_SETTING);
        $status = $this->requests->status();
        unset($status['upload_dir'], $status['requested_by']);
        $pending = $this->requests->pending();

        return [
            'dataset' => $this->requests->dataset(),
            'datasets' => PlaceImportOptions::DATASETS,
            'auto_refresh' => $this->requests->autoRefresh(),
            'counts' => [
                'places' => Place::query()->count(),
                'current_places' => $batch > 0 ? Place::query()->where('import_batch', '>=', $batch)->count() : Place::query()->count(),
                'regions' => DB::table('place_regions')->count(),
                'countries' => DB::table('place_countries')->count(),
                'accounts_with_location' => DB::table('users')->whereNotNull('place_id')->count(),
            ],
            'imported_at' => $batch > 0 ? now()->setTimestamp($batch)->toIso8601String() : null,
            'pending' => $pending === null ? null : [
                'mode' => $pending['mode'] ?? null,
                'rehome' => (bool) ($pending['rehome'] ?? false),
                'dry_run' => (bool) ($pending['dry_run'] ?? false),
                'uploaded' => ($pending['upload_dir'] ?? null) !== null,
                'source' => $pending['source'] ?? null,
                'requested_at' => $pending['requested_at'] ?? null,
            ],
            'status' => $status,
            'max_upload_bytes' => $this->maxUploadBytes(),
        ];
    }

    /**
     * The smaller of the app's own limit and PHP's upload limits.
     */
    private function maxUploadBytes(): int
    {
        $bytes = static function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };
        $limits = array_filter([
            AdminPlacesImportRequest::MAX_UPLOAD_KB * 1024,
            $bytes((string) ini_get('upload_max_filesize')),
            $bytes((string) ini_get('post_max_size')),
        ], static fn (int $limit): bool => $limit > 0);

        return min($limits);
    }
}

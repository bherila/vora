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
            // Apply exactly the preview the admin is looking at: one this page
            // queued, identified by its request id, with the dataset, mode,
            // rehoming and files it read. Anything else (a newer preview from
            // another tab, or a command-line preview with options this page
            // cannot repeat) is refused rather than silently substituted.
            $status = $this->requests->status();
            if (($status['state'] ?? null) !== 'succeeded'
                || ($status['dry_run'] ?? false) !== true
                || ($status['source'] ?? null) !== 'admin'
                || ($status['request_id'] ?? null) === null
                || $status['request_id'] !== $request->validated('preview_id')) {
                return response()->json(['success' => false, 'message' => 'That preview is no longer the latest one from this page. Reload and preview again.'], 409);
            }
            // Every page preview keeps the files it read (downloaded or
            // uploaded); without them it cannot be repeated exactly.
            $dir = $this->requests->previewUploadDir();
            if ($dir === null) {
                return response()->json(['success' => false, 'message' => 'The previewed files are no longer available. Upload them again.'], 422);
            }
        }

        if ($source === 'preview') {
            $options = [
                'mode' => (string) $status['mode'],
                'rehome' => $status['mode'] === PlaceImportOptions::MODE_REPLACE && (bool) ($status['rehome'] ?? false),
                'dry_run' => false,
                'upload_dir' => $dir,
                'source' => 'admin',
                'dataset' => (string) ($status['dataset'] ?? ''),
            ];
        } else {
            $replace = $request->validated('mode') === PlaceImportOptions::MODE_REPLACE;
            $options = [
                'mode' => (string) $request->validated('mode'),
                'rehome' => $replace && $request->boolean('rehome'),
                'dry_run' => $request->boolean('dry_run'),
                'upload_dir' => $dir,
                'source' => 'admin',
            ];
        }
        $this->requests->request($options, $request->user());

        return response()->json([
            'success' => true,
            'message' => $options['dry_run']
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

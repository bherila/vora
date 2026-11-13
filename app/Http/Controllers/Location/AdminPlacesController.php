<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\AdminPlacesImportRequest;
use App\Http\Requests\Location\AdminPlacesSettingsRequest;
use App\Models\Place;
use App\Models\PlaceImportRun;
use App\Services\Location\PlaceImporter;
use App\Services\Location\PlaceImportOptions;
use App\Services\Location\PlaceImportRuns;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin → City list: the GeoNames dataset, imports and their outcome. Imports
 * are queued here and run by the scheduler (`places:run-requested`).
 */
class AdminPlacesController extends Controller
{
    public function __construct(
        private readonly PlaceImportRuns $runs,
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
        $this->runs->updateSettings((string) $request->validated('dataset'), $request->boolean('auto_refresh'));

        return response()->json(['success' => true, 'message' => 'City list settings saved.', 'data' => $this->payload()]);
    }

    public function import(AdminPlacesImportRequest $request): JsonResponse
    {
        $source = (string) $request->validated('source');

        if ($source === 'preview') {
            // Apply exactly the preview the admin is looking at, read once
            // from its own immutable run record: its dataset, mode, rehoming
            // and the files it read. Anything else is refused, never
            // substituted.
            $preview = $this->runs->appliablePreview((string) $request->validated('preview_id'));
            if (is_string($preview)) {
                return response()->json(['success' => false, 'message' => $preview], 409);
            }
            $this->runs->queue([
                'mode' => $preview->mode,
                'rehome' => $preview->rehome,
                'dry_run' => false,
                'dataset' => $preview->dataset,
                'files_run_id' => $preview->files_run_id,
                'preview_of' => $preview->id,
            ], 'admin', $request->user());

            return $this->queued('Import queued. It starts within a minute.');
        }

        $id = (string) Str::uuid();
        $files = null;
        if ($source === 'upload') {
            $dir = $this->runs->filesDir($id);
            $cities = $request->file('cities');
            $cities?->move($dir, strtolower((string) $cities->getClientOriginalExtension()) === 'zip' ? 'cities.zip' : 'cities.txt');
            $request->file('admin1')?->move($dir, 'admin1CodesASCII.txt');
            $request->file('countries')?->move($dir, 'countryInfo.txt');
            $files = $id;
        }

        $this->runs->queue([
            'mode' => (string) $request->validated('mode'),
            'rehome' => $request->boolean('rehome'),
            'dry_run' => $request->boolean('dry_run'),
            'files_run_id' => $files,
        ], 'admin', $request->user(), $id);

        return $this->queued($request->boolean('dry_run') ? 'Preview queued. It starts within a minute.' : 'Import queued. It starts within a minute.');
    }

    public function cancel(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $id = is_string($id) && Str::isUuid($id) ? $id : $this->runs->nextPending()?->id;
        if ($id === null || ! $this->runs->cancel($id)) {
            return response()->json(['success' => false, 'message' => 'That import has already started or ended.', 'data' => $this->payload()], 409);
        }

        return response()->json(['success' => true, 'message' => 'Queued import cancelled.', 'data' => $this->payload()]);
    }

    private function queued(string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $this->payload()], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $batch = $this->settings->getInt(PlaceImporter::CURRENT_BATCH_SETTING);
        $pending = $this->runs->nextPending();
        $status = $this->runs->running() ?? $this->runs->lastFinished();

        return [
            'dataset' => $this->runs->dataset(),
            'datasets' => PlaceImportOptions::DATASETS,
            'auto_refresh' => $this->runs->autoRefresh(),
            'counts' => [
                'places' => Place::query()->count(),
                'current_places' => $batch > 0 ? Place::query()->where('import_batch', '>=', $batch)->count() : Place::query()->count(),
                'regions' => DB::table('place_regions')->count(),
                'countries' => DB::table('place_countries')->count(),
                'accounts_with_location' => DB::table('users')->whereNotNull('place_id')->count(),
            ],
            'imported_at' => $batch > 0 ? now()->setTimestamp($batch)->toIso8601String() : null,
            'pending' => $pending === null ? null : [
                'id' => $pending->id,
                'mode' => $pending->mode,
                'rehome' => $pending->rehome,
                'dry_run' => $pending->dry_run,
                'uploaded' => $pending->files_run_id !== null && $pending->preview_of === null,
                'source' => $pending->source,
                'requested_at' => $pending->requested_at?->toIso8601String(),
            ],
            'status' => $status === null ? null : $this->runPayload($status),
            'max_upload_bytes' => $this->maxUploadBytes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(PlaceImportRun $run): array
    {
        return [
            'request_id' => $run->id,
            'state' => $run->state,
            'source' => $run->source,
            'dataset' => $run->dataset,
            'mode' => $run->mode,
            'rehome' => $run->rehome,
            'dry_run' => $run->dry_run,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => $run->error,
            'result' => $run->result,
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

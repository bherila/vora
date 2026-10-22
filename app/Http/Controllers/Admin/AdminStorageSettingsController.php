<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStorageSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class AdminStorageSettingsController extends Controller
{
    public function __invoke(UpdateStorageSettingsRequest $request, SettingsService $settings): JsonResponse
    {
        $value = $request->validated('default_storage_limit_bytes');
        $settings->setDefaultStorageLimitBytes($value === null ? null : (int) $value);

        return response()->json([
            'success' => true,
            'data' => ['default_storage_limit_bytes' => $settings->defaultStorageLimitBytes()],
        ]);
    }
}

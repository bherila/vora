<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMediaSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class AdminMediaSettingsController extends Controller
{
    public function __invoke(UpdateMediaSettingsRequest $request, SettingsService $settings): JsonResponse
    {
        $settings->set(SettingsService::PRE_REVIEW_VIDEO_PROCESSING, $request->boolean('pre_review_video_processing'));

        return response()->json([
            'success' => true,
            'data' => ['pre_review_video_processing' => $settings->preReviewVideoProcessing()],
        ]);
    }
}

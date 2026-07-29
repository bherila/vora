<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'onboarding_dismissed_at' => now(),
        ])->save();

        return response()->json(['success' => true]);
    }
}

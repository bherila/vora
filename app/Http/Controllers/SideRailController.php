<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Profile\RecentProfileTrail;
use App\Services\Profile\SideRailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SideRailController extends Controller
{
    public function show(Request $request, SideRailService $sideRail): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['success' => true, 'data' => $sideRail->payload($viewer)]);
    }

    public function clearHistory(Request $request, RecentProfileTrail $trail): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $trail->clear($viewer);

        return response()->json(['success' => true]);
    }
}

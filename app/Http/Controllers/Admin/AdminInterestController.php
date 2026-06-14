<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminInterestStoreRequest;
use App\Http\Requests\Admin\AdminInterestUpdateRequest;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminInterestController extends Controller
{
    /**
     * Admin interest catalog page.
     */
    public function index(): View
    {
        return view('admin.interests');
    }

    /**
     * JSON list for admin UI.
     */
    public function apiIndex(): JsonResponse
    {
        $interests = Interest::query()
            ->with('parent')
            ->orderBy('parent_interest_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $interests->map(fn (Interest $interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'description' => $interest->description,
                'parent_interest_id' => $interest->parent_interest_id,
                'parent_name' => $interest->parent?->name,
                'created_at' => $interest->created_at?->toIso8601String(),
                'updated_at' => $interest->updated_at?->toIso8601String(),
            ])->toArray(),
        ]);
    }

    public function store(AdminInterestStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $interest = Interest::query()->create($data);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $interest->id,
                'name' => $interest->name,
                'description' => $interest->description,
                'parent_interest_id' => $interest->parent_interest_id,
                'parent_name' => $interest->parent?->name,
                'created_at' => $interest->created_at?->toIso8601String(),
                'updated_at' => $interest->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(AdminInterestUpdateRequest $request, Interest $interest): JsonResponse
    {
        $data = $request->validated();
        $interest->fill($data);
        $interest->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $interest->id,
                'name' => $interest->name,
                'description' => $interest->description,
                'parent_interest_id' => $interest->parent_interest_id,
                'parent_name' => $interest->parent?->name,
                'created_at' => $interest->created_at?->toIso8601String(),
                'updated_at' => $interest->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Interest $interest): JsonResponse
    {
        if ($interest->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an interest that has child interests.',
            ], 409);
        }

        $interest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest deleted.',
        ]);
    }
}

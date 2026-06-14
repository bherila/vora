<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminInterestStoreRequest;
use App\Http\Requests\Admin\AdminInterestUpdateRequest;
use App\Http\Requests\Admin\AdminInterestRequestDecisionRequest;
use App\Http\Requests\Admin\AdminInterestRequestCrudRequest;
use App\Models\Interest;
use App\Models\InterestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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

    /**
     * JSON list for pending requests.
     */
    public function apiRequestIndex(): JsonResponse
    {
        $requests = InterestRequest::query()
            ->with(['user', 'parentInterest'])
            ->where('status', InterestRequest::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests->map(fn (InterestRequest $interestRequest): array => [
                'id' => $interestRequest->id,
                'name' => $interestRequest->name,
                'description' => $interestRequest->description,
                'parent_interest_id' => $interestRequest->parent_interest_id,
                'parent_name' => $interestRequest->parentInterest?->name,
                'requested_by' => $interestRequest->user?->email,
                'requested_by_id' => $interestRequest->user?->id,
                'requested_by_name' => $interestRequest->user?->name,
                'requested_at' => $interestRequest->created_at?->toIso8601String(),
                'status' => $interestRequest->status,
                'reviewer_notes' => $interestRequest->reviewer_notes,
                'reviewed_at' => $interestRequest->reviewed_at?->toIso8601String(),
            ])->toArray(),
        ]);
    }

    public function updateRequest(AdminInterestRequestCrudRequest $request, InterestRequest $interestRequest): JsonResponse
    {
        if ($interestRequest->status !== InterestRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be edited.',
            ], Response::HTTP_CONFLICT);
        }

        $interestRequest->fill($request->validated())->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $interestRequest->id,
                'name' => $interestRequest->name,
                'description' => $interestRequest->description,
                'parent_interest_id' => $interestRequest->parent_interest_id,
                'status' => $interestRequest->status,
                'requested_by_name' => $interestRequest->user?->name,
                'requested_by' => $interestRequest->user?->email,
                'requested_at' => $interestRequest->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroyRequest(Request $request, InterestRequest $interestRequest): JsonResponse
    {
        if ($interestRequest->status !== InterestRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be removed.',
            ], Response::HTTP_CONFLICT);
        }

        if ($request->user() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $interestRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest request deleted.',
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

    public function approveRequest(AdminInterestRequestDecisionRequest $request, InterestRequest $interestRequest): JsonResponse
    {
        if ($interestRequest->status !== InterestRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be approved.',
            ], Response::HTTP_CONFLICT);
        }

        try {
            DB::transaction(function () use ($request, $interestRequest): void {
                $admin = $request->user();
                if ($admin === null) {
                    return;
                }

                Interest::query()->create([
                    'name' => $interestRequest->name,
                    'description' => $interestRequest->description,
                    'parent_interest_id' => $interestRequest->parent_interest_id,
                ]);

                $interestRequest->forceFill([
                    'status' => InterestRequest::STATUS_APPROVED,
                    'reviewed_by_user_id' => $admin->id,
                    'reviewed_at' => now(),
                    'reviewer_notes' => $request->validated()['reviewer_notes'] ?? null,
                ])->save();
            });
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Could not approve the request.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interest request approved.',
        ]);
    }

    public function rejectRequest(AdminInterestRequestDecisionRequest $request, InterestRequest $interestRequest): JsonResponse
    {
        if ($interestRequest->status !== InterestRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be rejected.',
            ], Response::HTTP_CONFLICT);
        }

        $admin = $request->user();
        if ($admin === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $interestRequest->forceFill([
            'status' => InterestRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $request->validated()['reviewer_notes'] ?? null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Interest request rejected.',
        ]);
    }
}

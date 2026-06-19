<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WaitlistRequest;
use App\Services\WaitlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminWaitlistController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    /**
     * Admin waitlist page (mounts the React UI).
     */
    public function index(): View
    {
        return view('admin.waitlist');
    }

    /**
     * JSON list of waitlist requests, newest first.
     */
    public function apiIndex(): JsonResponse
    {
        $requests = WaitlistRequest::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (WaitlistRequest $r): array => $this->present($r));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * Admit a verified request: mint an auto-approving invite bound to the email
     * and email the invite link to the requester.
     */
    public function admit(Request $request, WaitlistRequest $waitlistRequest): JsonResponse
    {
        if ($waitlistRequest->isAdmitted()) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been admitted.',
            ], 422);
        }

        if (! $waitlistRequest->isVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'This request has not verified its email yet.',
            ], 422);
        }

        $this->waitlist->admit($waitlistRequest, $request->user());

        return response()->json(['success' => true, 'data' => $this->present($waitlistRequest->refresh())]);
    }

    /**
     * Delete (reject) a request.
     */
    public function destroy(WaitlistRequest $waitlistRequest): JsonResponse
    {
        $waitlistRequest->delete();

        return response()->json(['success' => true, 'message' => 'Request deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WaitlistRequest $request): array
    {
        return [
            'uuid' => $request->uuid,
            'email' => $request->email,
            'birth_date' => $request->birth_date?->toDateString(),
            'interests' => $request->interests,
            'ip_address' => $request->ip_address,
            'geo' => $request->geo ?? [],
            'is_verified' => $request->isVerified(),
            'verified_at' => $request->verified_at?->toIso8601String(),
            'is_admitted' => $request->isAdmitted(),
            'admitted_at' => $request->admitted_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaitlistRequestRequest;
use App\Http\Requests\WaitlistVerifyRequest;
use App\Models\WaitlistRequest;
use App\Services\SettingsService;
use App\Services\WaitlistService;
use App\Support\CloudflareRequestInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public "request an invitation" flow. Only meaningful while public signups are
 * closed; when they're open, guests are pointed at the normal register page.
 */
class WaitlistController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly WaitlistService $waitlist,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->settings->publicSignupsEnabled()) {
            return redirect()->route('register');
        }

        $cf = CloudflareRequestInfo::from($request);

        return view('request-invitation', [
            'waitlistBootstrap' => [
                'ip' => $cf['ip'],
                'geo' => $cf['geo'],
            ],
        ]);
    }

    public function store(WaitlistRequestRequest $request): JsonResponse
    {
        if ($this->settings->publicSignupsEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Public sign-ups are open — you can create an account directly.',
            ], 422);
        }

        // Re-read CF info server-side; never trust IP/geo sent from the client.
        $cf = CloudflareRequestInfo::from($request);
        $waitlist = $this->waitlist->submit($request->validated(), $cf);

        return response()->json([
            'success' => true,
            'message' => 'Check your email to verify your request.',
            'data' => ['uuid' => $waitlist->uuid],
        ]);
    }

    public function verifyLink(string $uuid, string $token): View
    {
        $waitlist = WaitlistRequest::query()->where('uuid', $uuid)->first();
        $verified = $waitlist !== null && $this->waitlist->verify($waitlist, $token);

        return view('request-invitation-verified', ['verified' => $verified]);
    }

    public function verifyCode(WaitlistVerifyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $waitlist = WaitlistRequest::query()->where('uuid', $data['uuid'])->first();

        if ($waitlist === null || ! $this->waitlist->verify($waitlist, $data['code'])) {
            return response()->json([
                'success' => false,
                'message' => 'That code is incorrect or has expired.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your email is verified. We\'ll email you an invite once your request is reviewed.',
        ]);
    }
}

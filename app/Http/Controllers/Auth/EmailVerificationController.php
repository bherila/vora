<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MailDelivery;
use App\Services\Events\EventJoining;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()?->hasVerifiedEmail()
            ? $this->afterVerified($request)
            : view('auth.verify-email', ['initialData' => app(EventJoining::class)->initialData($request)]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill(); // marks verified + fires the Verified event
        }

        return $this->afterVerified($request);
    }

    /**
     * Send a verified user onward: approved users into the app, everyone else to
     * the pending-approval page. Approved users (e.g. the bootstrap admin or anyone
     * approved before clicking the link) must not see a false pending state.
     */
    private function afterVerified(Request $request): RedirectResponse
    {
        return redirect(app(AuthUserPolicy::class)->redirectAfterLogin($request->user(), $request));
    }

    /**
     * Where the latest verification email went and what its provider reported,
     * so a mistyped or rejecting address is visible instead of a silent wait.
     * Only reports for the account's current address are considered.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $delivery = MailDelivery::query()
            ->where('user_id', $user->id)
            ->where('recipient', mb_strtolower($user->email))
            ->where('type', 'VerifyEmail')
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->first(['status', 'sent_at', 'status_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'email' => $user->email,
                'email_locked' => (bool) $user->email_locked,
                'verified' => $user->hasVerifiedEmail(),
                'delivery' => $delivery === null ? null : [
                    'status' => $delivery->status,
                    'sent_at' => $delivery->sent_at->toIso8601String(),
                ],
            ],
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('approval.pending');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}

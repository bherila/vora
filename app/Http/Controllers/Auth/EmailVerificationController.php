<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Events\EventJoining;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
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

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('approval.pending');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}

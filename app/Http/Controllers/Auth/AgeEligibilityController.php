<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AgeEligibility;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgeEligibilityController extends Controller
{
    public function __invoke(Request $request, AgeEligibility $eligibility, AuthUserPolicy $policy): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail() || ! $user->isApproved() || $eligibility->reason($user) === null) {
            return redirect($policy->redirectAfterLogin($user, $request));
        }

        return view('auth.age-eligibility', ['reason' => $eligibility->reason($user), 'policy' => $eligibility->policy()]);
    }
}

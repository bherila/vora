<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreBirthDateRequest;
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

        return view('auth.age-eligibility', [
            'reason' => $eligibility->reason($user),
            'policy' => $eligibility->policy(),
            'needsBirthDate' => $user->birth_date === null,
        ]);
    }

    public function storeBirthDate(StoreBirthDateRequest $request): RedirectResponse
    {
        $request->user()->forceFill(['birth_date' => $request->validated('birth_date')])->save();

        // Back through the gate, which sends an eligible account onward.
        return redirect()->route('age.eligibility');
    }
}

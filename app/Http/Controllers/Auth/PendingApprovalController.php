<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Events\EventJoining;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PendingApprovalController extends Controller
{
    public function __invoke(Request $request, EventJoining $joining, AuthUserPolicy $policy): View|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail() || $request->user()->isApproved()) {
            return redirect($policy->redirectAfterLogin($request->user(), $request));
        }

        return view('auth.pending-approval', ['initialData' => $joining->initialData($request)]);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use Illuminate\View\View;

final class EmergencyLoginController
{
    public function show(): View
    {
        return view('auth.emergency-login');
    }

    public function twoFactor(string $token): View
    {
        return view('auth.emergency-login', ['attemptToken' => $token]);
    }
}

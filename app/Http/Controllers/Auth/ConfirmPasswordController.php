<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Records a fresh password confirmation for the session, which
 * RequireConfirmedPassword checks before passkey enrollment.
 */
class ConfirmPasswordController extends Controller
{
    public function store(ConfirmPasswordRequest $request): JsonResponse
    {
        $request->session()->passwordConfirmed();

        return response()->json([
            'success' => true,
            'message' => 'Password confirmed.',
            'expires_in' => (int) config('auth.password_timeout', 10800),
        ]);
    }
}

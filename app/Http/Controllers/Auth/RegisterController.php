<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $isFirstUser = User::count() === 0;
        $signupRedirect = route('verification.notice', ! $isFirstUser ? ['signup_status' => 'pending-approval'] : []);

        $user = new User;
        $user->name = $data['name'];
        $user->display_name = $data['display_name'];
        $user->birth_date = $data['birth_date'];
        $user->email = $data['email'];
        $user->password = $data['password']; // hashed via the model cast
        $user->gender = $data['gender'];
        $user->gender_other = $data['gender'] === 'other' ? ($data['gender_other'] ?? null) : null;
        $user->user_type = $data['user_type'];
        $user->user_type_other = $data['user_type'] === 'other' ? ($data['user_type_other'] ?? null) : null;
        $user->preferred_user_types = $data['preferred_user_types'];
        $user->preferred_genders = $data['preferred_genders'];
        // Bootstrap: the very first account is an approved admin so the app is usable.
        if ($isFirstUser) {
            $user->is_admin = true;
            $user->approved_at = now();
        }
        $user->save();

        event(new Registered($user));
        Auth::login($user);

        if ($request->expectsJson()) {
            $payload = [
                'success' => true,
                'redirect' => $signupRedirect,
            ];

            if (! $isFirstUser) {
                $payload['status'] = 'Your account has been created and is pending admin approval.';
            }

            return response()->json($payload);
        }

        return redirect($signupRedirect);
    }
}

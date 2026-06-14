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

        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password']; // hashed via the model cast
        // Bootstrap: the very first account is an approved admin so the app is usable.
        if ($isFirstUser) {
            $user->is_admin = true;
            $user->approved_at = now();
        }
        $user->save();

        event(new Registered($user));
        Auth::login($user);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => route('verification.notice')]);
        }

        return redirect()->route('verification.notice');
    }
}

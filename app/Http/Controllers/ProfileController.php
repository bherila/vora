<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validated();
        $emailChanged = $user->email !== $data['email'];
        $nameChanged = $user->name !== $data['name'];
        if ($nameChanged && $user->name_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your name is locked and cannot be changed.',
            ], 403);
        }

        if ($emailChanged && $user->email_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your email is locked and cannot be changed.',
            ], 403);
        }

        if ($nameChanged) {
            $user->name = $data['name'];
        }
        if ($emailChanged) {
            $user->email = $data['email'];
        }
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'success' => true,
            'message' => $emailChanged ? 'Account updated. Please verify your new email address.' : 'Account updated.',
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}

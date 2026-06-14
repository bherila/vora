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
        $displayNameChanged = $user->display_name !== $data['display_name'];
        if ($nameChanged && $user->name_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your real name is locked and cannot be changed.',
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
        if ($displayNameChanged) {
            $user->display_name = $data['display_name'];
        }
        if ($emailChanged) {
            $user->email = $data['email'];
        }
        $user->gender = $data['gender'];
        $user->gender_other = $data['gender'] === 'other' ? ($data['gender_other'] ?? null) : null;
        $user->user_type = $data['user_type'];
        $user->user_type_other = $data['user_type'] === 'other' ? ($data['user_type_other'] ?? null) : null;
        $user->preferred_user_types = $data['preferred_user_types'];
        $user->preferred_genders = $data['preferred_genders'];
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
                'display_name' => $user->display_name,
                'email' => $user->email,
                'gender' => $user->gender,
                'gender_other' => $user->gender_other,
                'user_type' => $user->user_type,
                'user_type_other' => $user->user_type_other,
                'preferred_user_types' => $user->preferred_user_types,
                'preferred_genders' => $user->preferred_genders,
            ],
        ]);
    }
}

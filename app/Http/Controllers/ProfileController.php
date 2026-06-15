<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Http\Requests\Profile\CompleteProfilePictureRequest;
use App\Http\Requests\Profile\StoreProfilePictureRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaResponseService $responder,
    ) {}

    public function storeProfilePicture(StoreProfilePictureRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $result = $this->uploads->createPendingUpload(
            $user,
            MediaType::Photo,
            $request->validated('filename'),
            $request->validated('content_type'),
            'Profile picture',
            Visibility::Users,
            [],
            false,
            null,
            MediaPurpose::ProfilePicture,
        );

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($result['media']),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
        ], 201);
    }

    public function completeProfilePicture(CompleteProfilePictureRequest $request, Media $media): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($media->user_id !== $user->id || ! $media->isProfilePicture()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->uploads->completeUpload($media)) {
            return response()->json([
                'success' => false,
                'message' => 'Upload could not be verified — the file is missing or exceeds the size limit.',
            ], 422);
        }

        $user->profile_picture_media_id = $media->id;
        $user->save();

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media->refresh()),
        ]);
    }

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
        $gender = array_key_exists('gender', $data) ? $data['gender'] : $user->gender;
        $genderOther = array_key_exists('gender_other', $data) ? $data['gender_other'] : $user->gender_other;
        $userType = array_key_exists('user_type', $data) ? $data['user_type'] : $user->user_type;
        $userTypeOther = array_key_exists('user_type_other', $data) ? $data['user_type_other'] : $user->user_type_other;

        $user->gender = $gender;
        $user->gender_other = $gender === 'other' ? $genderOther : null;
        $user->user_type = $userType;
        $user->user_type_other = $userType === 'other' ? $userTypeOther : null;
        if (array_key_exists('preferred_user_types', $data)) {
            $user->preferred_user_types = $data['preferred_user_types'];
        }
        if (array_key_exists('preferred_genders', $data)) {
            $user->preferred_genders = $data['preferred_genders'];
        }
        if (array_key_exists('email_follow_request_received', $data)) {
            $user->email_follow_request_received = (bool) $data['email_follow_request_received'];
        }
        if (array_key_exists('email_follow_request_accepted', $data)) {
            $user->email_follow_request_accepted = (bool) $data['email_follow_request_accepted'];
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
                'display_name' => $user->display_name,
                'email' => $user->email,
                'gender' => $user->gender,
                'gender_other' => $user->gender_other,
                'user_type' => $user->user_type,
                'user_type_other' => $user->user_type_other,
                'preferred_user_types' => $user->preferred_user_types,
                'preferred_genders' => $user->preferred_genders,
                'email_follow_request_received' => (bool) $user->email_follow_request_received,
                'email_follow_request_accepted' => (bool) $user->email_follow_request_accepted,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Http\Requests\Character\CompleteCharacterProfilePictureRequest;
use App\Http\Requests\Character\StoreCharacterProfilePictureRequest;
use App\Http\Requests\Character\UpsertCharacterRequest;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CharacterController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaResponseService $responder,
        private readonly MediaService $media,
    ) {}

    public function page(): View
    {
        return view('user.characters');
    }

    public function index(): JsonResponse
    {
        $user = request()->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $characters = $user->characters()->with('profilePicture')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $characters->map(fn (Character $character): array => $this->present($character))->values(),
        ]);
    }

    public function store(UpsertCharacterRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $character = $user->characters()->create($this->payload($request->validated()));

        return response()->json(['success' => true, 'data' => $this->present($character->refresh())], 201);
    }

    public function update(UpsertCharacterRequest $request, Character $character): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $character->fill($this->payload($request->validated()))->save();

        return response()->json(['success' => true, 'data' => $this->present($character->refresh())]);
    }

    public function destroy(Character $character): JsonResponse
    {
        $user = request()->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $avatar = $character->profilePicture;
        $character->delete();

        // The character's avatar media is owned by the user; remove it now that
        // the character no longer references it (unless something else does).
        if ($avatar instanceof Media) {
            $this->media->deleteIfUnreferenced($avatar);
        }

        return response()->json(['success' => true]);
    }

    public function storeProfilePicture(StoreCharacterProfilePictureRequest $request, Character $character): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $result = $this->uploads->createPendingUpload(
            $user,
            MediaType::Photo,
            $request->validated('filename'),
            $request->validated('content_type'),
            $character->display_name.' profile picture',
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

    public function completeProfilePicture(CompleteCharacterProfilePictureRequest $request, Character $character, Media $media): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id || $media->user_id !== $user->id || ! $media->isProfilePicture()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->uploads->completeUpload($media)) {
            return response()->json(['success' => false, 'message' => 'Upload could not be verified.'], 422);
        }

        $previousMediaId = $character->profile_picture_media_id;
        $character->profile_picture_media_id = $media->id;
        $character->save();

        // Clean up the replaced avatar so it does not orphan its object/row.
        if ($previousMediaId !== null && $previousMediaId !== $media->id) {
            $previous = Media::query()->find($previousMediaId);
            if ($previous instanceof Media) {
                $this->media->deleteIfUnreferenced($previous);
            }
        }

        return response()->json(['success' => true, 'data' => $this->present($character->refresh())]);
    }

    public function removeProfilePicture(Character $character): JsonResponse
    {
        $user = request()->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $mediaId = $character->profile_picture_media_id;
        $character->profile_picture_media_id = null;
        $character->save();

        if ($mediaId !== null) {
            $media = Media::query()->find($mediaId);
            if ($media instanceof Media) {
                $this->media->deleteIfUnreferenced($media);
            }
        }

        return response()->json(['success' => true, 'data' => $this->present($character->refresh())]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(array $data): array
    {
        $gender = $data['gender'] ?? null;
        $userType = $data['user_type'] ?? null;

        return [
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'gender' => $gender,
            'gender_other' => $gender === 'other' ? ($data['gender_other'] ?? null) : null,
            'user_type' => $userType,
            'user_type_other' => $userType === 'other' ? ($data['user_type_other'] ?? null) : null,
            'preferred_user_types' => $data['preferred_user_types'] ?? null,
            'preferred_genders' => $data['preferred_genders'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Character $character): array
    {
        $picture = $character->profilePicture;

        return [
            'id' => $character->id,
            'display_name' => $character->display_name,
            'description' => $character->description,
            'gender' => $character->gender,
            'gender_other' => $character->gender_other,
            'user_type' => $character->user_type,
            'user_type_other' => $character->user_type_other,
            'preferred_user_types' => $character->preferred_user_types ?? [],
            'preferred_genders' => $character->preferred_genders ?? [],
            'inherit_interests' => $character->inherit_interests,
            'profile_picture' => $picture instanceof Media ? $this->responder->item($picture, resolveHls: false) : null,
        ];
    }
}

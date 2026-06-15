<?php

namespace App\Http\Controllers;

use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\Visibility;
use App\Http\Requests\Character\CompleteCharacterProfilePictureRequest;
use App\Http\Requests\Character\SetCharacterInterestInheritanceRequest;
use App\Http\Requests\Character\StoreCharacterProfilePictureRequest;
use App\Http\Requests\Character\UpsertCharacterRequest;
use App\Http\Requests\Interest\RateInterestRequest;
use App\Models\Character;
use App\Models\CharacterInterestRating;
use App\Models\Interest;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CharacterController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaResponseService $responder,
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

        $character->delete();

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

        $character->profile_picture_media_id = $media->id;
        $character->save();

        return response()->json(['success' => true, 'data' => $this->present($character->refresh())]);
    }

    /**
     * List the full interest catalog with this character's own rating overrides.
     * When the character inherits, its overrides are empty and the owning user's
     * profile interests apply instead.
     */
    public function interests(Request $request, Character $character): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $ratings = CharacterInterestRating::query()
            ->where('character_id', $character->id)
            ->pluck('level', 'interest_id');

        $interests = Interest::query()
            ->orderBy('parent_interest_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Interest $interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'description' => $interest->description,
                'parent_interest_id' => $interest->parent_interest_id,
                'rating' => $ratings->has($interest->id) ? (int) $ratings[$interest->id] : null,
            ]);

        return response()->json([
            'success' => true,
            'inherit_interests' => $character->inherit_interests,
            'data' => $interests,
        ]);
    }

    public function rateInterest(RateInterestRequest $request, Character $character, Interest $interest): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        // Setting an explicit rating means the character is overriding, not inheriting.
        if ($character->inherit_interests) {
            $character->inherit_interests = false;
            $character->save();
        }

        $level = (int) $request->validated()['level'];

        CharacterInterestRating::query()->updateOrCreate(
            ['character_id' => $character->id, 'interest_id' => $interest->id],
            ['level' => $level],
        );

        return response()->json([
            'success' => true,
            'data' => ['interest_id' => $interest->id, 'level' => $level],
        ]);
    }

    public function destroyInterestRating(Request $request, Character $character, Interest $interest): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        CharacterInterestRating::query()
            ->where('character_id', $character->id)
            ->where('interest_id', $interest->id)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Interest rating removed.']);
    }

    public function setInterestInheritance(SetCharacterInterestInheritanceRequest $request, Character $character): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $inherit = (bool) $request->validated()['inherit'];
        $character->inherit_interests = $inherit;
        $character->save();

        // Inheriting means the character keeps no overrides of its own; the
        // client snapshots them first so a mistaken toggle can be undone.
        if ($inherit) {
            $character->interestRatings()->delete();
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

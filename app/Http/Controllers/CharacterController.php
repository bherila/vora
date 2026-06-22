<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Http\Requests\Character\CompleteCharacterProfilePictureRequest;
use App\Http\Requests\Character\StoreCharacterProfilePictureRequest;
use App\Http\Requests\Character\UpsertCharacterRequest;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use App\Services\Privacy\PrivacyAuditor;
use App\Support\CharacterPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class CharacterController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaResponseService $responder,
        private readonly MediaService $media,
        private readonly PrivacyAuditor $auditor,
    ) {}

    public function index(): JsonResponse
    {
        $user = request()->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->charactersPayload($user),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function charactersPayload(User $user): Collection
    {
        $characters = $user->characters()->with(['profilePicture', 'audienceMembers'])->latest()->get();

        return collect(CharacterPresenter::list($characters, $this->responder));
    }

    public function store(UpsertCharacterRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $character = $user->characters()->create($this->payload($request));
        $this->syncCharacterAudience($request, $character);
        $this->auditor->recordCreation($character, $user, $character->privacySnapshot(), $request);

        return response()->json(['success' => true, 'data' => $this->present($character->refresh()->load('audienceMembers'))], 201);
    }

    public function update(UpsertCharacterRequest $request, Character $character): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $character->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $privacyBefore = $character->privacySnapshot();
        $character->fill($this->payload($request))->save();
        $this->syncCharacterAudience($request, $character);
        $this->auditor->record($character, $user, $privacyBefore, $character->privacySnapshot(), $request);
        $this->propagateCharacterPrivacy($character, $user, $request);

        return response()->json(['success' => true, 'data' => $this->present($character->refresh()->load('audienceMembers'))]);
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
            $character->audience,
            [],
            false,
            null,
            MediaPurpose::ProfilePicture,
            $character->discoverable,
            $character->id,
        );
        $media = $result['media'];
        $media->syncAudienceMembers($this->characterAudienceUserIds($character));
        $this->auditor->recordCreation($media, $user, $media->privacySnapshot(), $request);

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media),
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

    /** @return array<string, mixed> */
    private function payload(UpsertCharacterRequest $request): array
    {
        $data = $request->validated();
        $gender = $data['gender'] ?? null;
        $userType = $data['user_type'] ?? null;

        return [
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'audience' => $request->audience(),
            'discoverable' => $request->discoverable(),
            'gender' => $gender,
            'gender_other' => $gender === 'other' ? ($data['gender_other'] ?? null) : null,
            'user_type' => $userType,
            'user_type_other' => $userType === 'other' ? ($data['user_type_other'] ?? null) : null,
            'preferred_user_types' => $data['preferred_user_types'] ?? null,
            'preferred_genders' => $data['preferred_genders'] ?? null,
        ];
    }

    private function syncCharacterAudience(UpsertCharacterRequest $request, Character $character): void
    {
        $character->syncAudienceMembers(
            $request->audience() === Audience::SpecificPeople ? $request->audienceUserIds() : []
        );
    }

    private function propagateCharacterPrivacy(Character $character, User $actor, UpsertCharacterRequest $request): void
    {
        $memberIds = $this->characterAudienceUserIds($character);

        $character->media()->with('audienceMembers')->get()->each(function (Media $media) use ($actor, $character, $memberIds, $request): void {
            $privacyBefore = $media->privacySnapshot();
            $media->audience = $character->audience;
            $media->discoverable = $character->discoverable;
            $media->save();
            $media->syncAudienceMembers($memberIds);
            $this->auditor->record($media, $actor, $privacyBefore, $media->privacySnapshot(), $request);
        });
    }

    /**
     * @return list<int>
     */
    private function characterAudienceUserIds(Character $character): array
    {
        if ($character->audience !== Audience::SpecificPeople) {
            return [];
        }

        return $character->audienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    private function present(Character $character): array
    {
        return CharacterPresenter::manage($character, $this->responder);
    }
}

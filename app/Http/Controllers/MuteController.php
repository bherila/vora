<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mute\StoreMuteRequest;
use App\Models\Character;
use App\Models\Mute;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MuteController extends Controller
{
    public function __construct(private readonly MediaResponseService $mediaResponder) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $mutes = Mute::query()
            ->where('user_id', $viewer->id)
            ->with([
                'mutedUser.profilePicture',
                'mutedCharacter.profilePicture',
            ])
            ->latest()
            ->get()
            ->map(function (Mute $mute) use ($viewer): ?array {
                if ($mute->mutedCharacter instanceof Character) {
                    $character = $mute->mutedCharacter;

                    // A Separate persona is always presented as itself. Never
                    // load or serialize its owner on the viewer's settings list.
                    return [
                        'type' => 'character',
                        'id' => $character->id,
                        'display_name' => $character->display_name,
                        'avatar_url' => UserPresenter::pictureUrl(
                            $character->profilePicture,
                            $this->mediaResponder,
                            $viewer,
                        ),
                        'profile_url' => route('characters.view', ['ulid' => $character->ulid], false),
                    ];
                }

                if ($mute->mutedUser instanceof User) {
                    $user = $mute->mutedUser;

                    return [
                        'type' => 'user',
                        'id' => $user->id,
                        'display_name' => $user->display_name ?: $user->name,
                        'avatar_url' => UserPresenter::avatarUrl($user, $this->mediaResponder, $viewer),
                        'profile_url' => route('users.profile', $user, false),
                    ];
                }

                return null;
            })
            ->filter()
            ->values();

        return response()->json(['success' => true, 'data' => $mutes]);
    }

    public function store(StoreMuteRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $target = $this->resolveTarget($request);

        if ($target instanceof User && $viewer->is($target)) {
            return response()->json(['success' => false, 'message' => 'You cannot mute yourself.'], 422);
        }
        if ($target instanceof Character && $target->user_id === $viewer->id) {
            return response()->json(['success' => false, 'message' => 'You cannot mute your own persona.'], 422);
        }

        $attributes = $target instanceof Character
            ? ['muted_user_id' => null, 'muted_character_id' => $target->id]
            : ['muted_user_id' => $target->id, 'muted_character_id' => null];

        Mute::query()->firstOrCreate(['user_id' => $viewer->id, ...$attributes]);

        return response()->json(['success' => true, 'data' => ['muted' => true]], 201);
    }

    public function destroy(StoreMuteRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $id = (int) $request->validated('id');
        $isCharacter = $request->validated('type') === 'character';

        Mute::query()
            ->where('user_id', $viewer->id)
            ->when(
                $isCharacter,
                fn ($query) => $query->where('muted_character_id', $id)->whereNull('muted_user_id'),
                fn ($query) => $query->where('muted_user_id', $id)->whereNull('muted_character_id'),
            )
            ->delete();

        return response()->json(['success' => true, 'data' => ['muted' => false]]);
    }

    private function resolveTarget(StoreMuteRequest $request): User|Character
    {
        $viewer = $request->user();
        $id = (int) $request->validated('id');

        if ($request->validated('type') === 'character') {
            $character = Character::query()->with('user')->find($id);
            if (! $character instanceof Character || ! $character->isViewableBy($viewer)) {
                abort(404, 'Not found.');
            }

            return $character;
        }

        $user = User::query()
            ->whereKey($id)
            ->whereNotNull('approved_at')
            ->active()
            ->first();
        if (! $user instanceof User) {
            abort(404, 'Not found.');
        }

        return $user;
    }
}

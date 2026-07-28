<?php

namespace App\Services\Privacy;

use App\Enums\ViewAsMode;
use App\Models\Character;
use App\Models\User;
use App\Support\ActiveIdentity;
use Illuminate\Http\Request;

/**
 * Request-local context for owner-only profile previews.
 *
 * The synthetic viewer is deliberately a normal, non-admin User model with an
 * impossible database id. ProfileGate and HasPrivacyPolicy therefore execute
 * their real owner/admin/allowlist logic. FollowGraph alone supplies the one
 * simulated edge for Follower mode, in both its boolean and SQL forms.
 */
class ViewAsContext
{
    public const SIMULATED_VIEWER_ID = 0;

    private ?ViewAsMode $mode = null;

    private ?int $ownerId = null;

    private ?int $characterId = null;

    public function __construct(private readonly ActiveIdentity $activeIdentity) {}

    public function mode(): ?ViewAsMode
    {
        return $this->mode;
    }

    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    public function characterId(): ?int
    {
        return $this->characterId;
    }

    public function isSimulatedViewer(int $viewerId): bool
    {
        return $this->mode !== null && $viewerId === self::SIMULATED_VIEWER_ID;
    }

    /**
     * Resolve an optional view_as query for this exact owner/active identity.
     * Invalid modes, foreign owners, and identity mismatches share one generic
     * 404 so the preview endpoint is not an ownership oracle.
     */
    public function viewerFor(Request $request, User $owner, ?Character $character = null): User
    {
        $rawMode = $request->query('view_as');
        if ($rawMode === null) {
            $viewer = $request->user();
            abort_unless($viewer instanceof User, 404, 'Not found.');

            return $viewer;
        }

        $mode = is_string($rawMode) ? ViewAsMode::tryFrom($rawMode) : null;
        $authenticated = $request->user();
        $activeCharacterId = $authenticated instanceof User
            ? $this->activeIdentity->id($request, $authenticated)
            : null;

        abort_unless(
            $mode instanceof ViewAsMode
                && $authenticated instanceof User
                && $authenticated->id === $owner->id
                && ($character === null || $character->user_id === $owner->id)
                && $activeCharacterId === $character?->id,
            404,
            'Not found.',
        );

        return $this->simulate($mode, $owner, $character);
    }

    /**
     * Activate a typed simulation directly. Public for focused parity tests;
     * HTTP callers must go through viewerFor() for owner/identity validation.
     */
    public function simulate(ViewAsMode $mode, User $owner, ?Character $character = null): User
    {
        abort_if(
            $character instanceof Character && $character->user_id !== $owner->id,
            404,
            'Not found.',
        );

        $this->mode = $mode;
        $this->ownerId = (int) $owner->id;
        $this->characterId = $character?->id;

        $viewer = new User;
        $viewer->forceFill([
            'id' => self::SIMULATED_VIEWER_ID,
            'name' => 'Simulated profile viewer',
            'display_name' => 'Simulated profile viewer',
            'email' => 'view-as@invalid.local',
            'approved_at' => now(),
            'is_admin' => false,
            'is_disabled' => false,
        ]);

        return $viewer;
    }

    /**
     * @return array{mode: string, audience: string}|null
     */
    public function payload(): ?array
    {
        if (! $this->mode instanceof ViewAsMode) {
            return null;
        }

        return [
            'mode' => $this->mode->value,
            'audience' => $this->mode->audienceDescription(),
        ];
    }
}

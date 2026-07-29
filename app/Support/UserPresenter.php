<?php

namespace App\Support;

use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaResponseService;

/**
 * Serializes the small, public identity slice of a User — id, display name, and
 * a signed avatar URL — shared by every surface that names a user (feed authors,
 * comments, the follow directory, the navbar). Centralising it keeps those
 * surfaces from drifting and keeps the avatar-signing rule in one place.
 *
 * The avatar URL is only produced when a {@see MediaResponseService} is supplied
 * (the same responder the media library uses) and the user's profile-picture
 * relation is loaded; otherwise it is null. Callers that emit identities for a
 * listing must eager-load `profilePicture` to avoid an N+1.
 */
class UserPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function identity(?User $user, ?MediaResponseService $responder = null, ?User $viewer = null): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name ?? $user->name,
            'avatar_url' => self::avatarUrl($user, $responder, $viewer),
        ];
    }

    /**
     * The signed thumbnail URL of the user's profile picture, or null when none
     * is set, the picture is not ready, or no responder was supplied. Prefers the
     * small thumbnail and falls back to the full image (profile pictures are
     * always photos, so the full URL is signed too).
     */
    public static function avatarUrl(?User $user, ?MediaResponseService $responder, ?User $viewer = null): ?string
    {
        if ($user === null) {
            return null;
        }

        return self::pictureUrl($user->profilePicture, $responder, $viewer);
    }

    /**
     * The signed thumbnail URL for any profile picture (user or character),
     * applying the same moderation gate: a picture that is not yet approved is
     * shown only to its owner. Everyone else gets null (the UI falls back to
     * initials) until an admin approves it — so unreviewed images never reach
     * other viewers. Pass the current viewer to let owners preview their own
     * pending upload; omit it (default) for the safe, approved-only behaviour.
     */
    public static function pictureUrl(?Media $picture, ?MediaResponseService $responder, ?User $viewer = null): ?string
    {
        if (! $picture instanceof Media || $responder === null) {
            return null;
        }

        if (! $picture->isApprovedContent() && $picture->user_id !== $viewer?->id) {
            return null;
        }

        if ($viewer?->id !== $picture->user_id && ! $viewer?->isAdmin()) {
            return $responder->visitorAssetUrl($picture);
        }

        $payload = $responder->item($picture, resolveHls: false);

        return $payload['thumbnail_url'] ?? $payload['url'] ?? null;
    }
}

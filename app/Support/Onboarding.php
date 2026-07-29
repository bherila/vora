<?php

namespace App\Support;

use App\Models\FollowRequest;
use App\Models\InterestRating;
use App\Models\Post;
use App\Models\User;

/**
 * First-run guide shown on the feed (/feed, the post-login landing). Returns
 * null once every required step is complete or the account has dismissed it.
 * Each flag is a single existence check, kept cheap.
 */
class Onboarding
{
    /**
     * @return array{
     *     display_name: string,
     *     has_personas: bool,
     *     steps: array{
     *         has_avatar: bool,
     *         has_interests: bool,
     *         is_following: bool,
     *         has_posted: bool
     *     }
     * }|null
     */
    public static function payload(User $viewer): ?array
    {
        if ($viewer->onboarding_dismissed_at !== null) {
            return null;
        }

        $steps = [
            'has_avatar' => $viewer->profile_picture_media_id !== null,
            'has_interests' => InterestRating::query()
                ->where('user_id', $viewer->id)
                ->whereNull('character_id')
                ->where('level', '>', 0)
                ->exists(),
            'is_following' => FollowRequest::query()
                ->where('requester_id', $viewer->id)
                ->where('status', 'accepted')
                // Onboarding asks whether the human follows another human;
                // opting into a persona's audience does not complete this step.
                ->whereNull('recipient_character_id')
                ->exists(),
            'has_posted' => Post::query()->where('user_id', $viewer->id)->exists(),
        ];

        if (! in_array(false, $steps, true)) {
            return null;
        }

        return [
            'display_name' => $viewer->display_name ?: $viewer->name,
            // Personas are optional. This flag controls a separate invitation;
            // it never contributes to the required-step count.
            'has_personas' => $viewer->characters()->exists(),
            'steps' => $steps,
        ];
    }
}

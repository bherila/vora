<?php

namespace App\Support;

use App\Models\FollowRequest;
use App\Models\InterestRating;
use App\Models\Post;
use App\Models\User;

/**
 * First-run checklist state shown on the feed (/feed, the post-login landing).
 * Returns null once every step is complete so the checklist disappears for
 * established users. Each flag is a single existence check, kept cheap.
 *
 * Deliberately no persona step: personas are an opt-in layer most users never
 * touch, so onboarding must not suggest one is expected.
 */
class Onboarding
{
    /**
     * @return array<string, bool>|null
     */
    public static function steps(User $viewer): ?array
    {
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
                ->exists(),
            'has_posted' => Post::query()->where('user_id', $viewer->id)->exists(),
        ];

        return in_array(false, $steps, true) ? $steps : null;
    }
}

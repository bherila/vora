<?php

namespace Database\Factories;

use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryAuthor>
 */
class StoryAuthorFactory extends Factory
{
    protected $model = StoryAuthor::class;

    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'user_id' => User::factory(),
            'invited_by_user_id' => null,
            'role' => StoryAuthor::ROLE_CO_AUTHOR,
            'status' => StoryAuthor::STATUS_PENDING,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['status' => StoryAuthor::STATUS_ACCEPTED, 'responded_at' => now()]);
    }

    public function owner(): static
    {
        return $this->state(fn (): array => [
            'role' => StoryAuthor::ROLE_OWNER,
            'status' => StoryAuthor::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }
}

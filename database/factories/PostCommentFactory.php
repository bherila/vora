<?php

namespace Database\Factories;

use App\Enums\ModerationStatus;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostComment>
 */
class PostCommentFactory extends Factory
{
    protected $model = PostComment::class;

    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'body' => fake()->sentence(),
            'moderation_status' => ModerationStatus::Approved,
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Rejected]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ulid' => (string) Str::ulid(),
            'body' => fake()->sentence(),
            'audience' => Audience::Everyone,
            'discoverable' => true,
            'moderation_status' => ModerationStatus::Pending,
        ];
    }

    public function audience(Audience $audience): static
    {
        return $this->state(fn (): array => ['audience' => $audience]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Rejected]);
    }

    /** Link-only: discoverable off, audience unchanged. */
    public function unlisted(): static
    {
        return $this->state(fn (): array => ['discoverable' => false]);
    }
}

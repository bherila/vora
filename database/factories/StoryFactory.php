<?php

namespace Database\Factories;

use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Enums\StoryType;
use App\Enums\Visibility;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ulid' => (string) Str::ulid(),
            'title' => fake()->sentence(3),
            'type' => StoryType::LongForm,
            'status' => StoryStatus::Draft,
            'body' => fake()->paragraphs(2, true),
            'visibility' => Visibility::Users,
            'moderation_status' => ModerationStatus::Pending,
        ];
    }

    /**
     * Ensure every story has its owner authorship row, mirroring the controller.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Story $story): void {
            $story->authors()->firstOrCreate(
                ['user_id' => $story->user_id],
                [
                    'role' => StoryAuthor::ROLE_OWNER,
                    'status' => StoryAuthor::STATUS_ACCEPTED,
                    'responded_at' => now(),
                ],
            );
        });
    }

    public function cyoa(): static
    {
        return $this->state(fn (): array => ['type' => StoryType::Cyoa, 'body' => null]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => StoryStatus::Published, 'published_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Rejected]);
    }

    public function unlisted(): static
    {
        return $this->state(fn (): array => ['visibility' => Visibility::Unlisted]);
    }

    /**
     * A published + admin-approved story, the state in which non-authors can read it.
     */
    public function readable(): static
    {
        return $this->published()->approved();
    }
}

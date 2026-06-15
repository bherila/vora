<?php

namespace Database\Factories;

use App\Models\Story;
use App\Models\StoryNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryNode>
 */
class StoryNodeFactory extends Factory
{
    protected $model = StoryNode::class;

    public function definition(): array
    {
        return [
            'story_id' => Story::factory()->cyoa(),
            'key' => (string) Str::ulid(),
            'title' => fake()->words(2, true),
            'body' => fake()->paragraph(),
            'is_start' => false,
            'position_x' => fake()->numberBetween(0, 800),
            'position_y' => fake()->numberBetween(0, 600),
        ];
    }

    public function start(): static
    {
        return $this->state(fn (): array => ['is_start' => true]);
    }
}

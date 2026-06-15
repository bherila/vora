<?php

namespace Database\Factories;

use App\Models\StoryChoice;
use App\Models\StoryNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryChoice>
 */
class StoryChoiceFactory extends Factory
{
    protected $model = StoryChoice::class;

    public function definition(): array
    {
        $from = StoryNode::factory();

        return [
            'story_id' => fn (array $attributes) => StoryNode::find($attributes['from_node_id'])?->story_id,
            'from_node_id' => $from,
            'to_node_id' => null,
            'label' => fake()->words(3, true),
            'position' => 0,
        ];
    }
}

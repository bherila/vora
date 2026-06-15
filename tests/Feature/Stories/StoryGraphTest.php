<?php

namespace Tests\Feature\Stories;

use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_save_and_read_a_cyoa_graph(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->cyoa()->create();

        $payload = [
            'nodes' => [
                ['key' => 'start', 'title' => 'Start', 'body' => 'You wake up.', 'is_start' => true, 'position_x' => 0, 'position_y' => 0],
                ['key' => 'left', 'title' => 'Left', 'body' => 'A dark hall.', 'is_start' => false, 'position_x' => 100, 'position_y' => 100],
                ['key' => 'right', 'title' => 'Right', 'body' => 'The end.', 'is_start' => false, 'position_x' => 200, 'position_y' => 100],
            ],
            'choices' => [
                ['from' => 'start', 'to' => 'left', 'label' => 'Go left', 'position' => 0],
                ['from' => 'start', 'to' => 'right', 'label' => 'Go right', 'position' => 1],
                ['from' => 'left', 'to' => null, 'label' => 'Give up', 'position' => 0],
            ],
        ];

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", $payload)
            ->assertOk()
            ->assertJsonCount(3, 'data.nodes')
            ->assertJsonCount(3, 'data.choices');

        $this->assertDatabaseCount('story_nodes', 3);
        $this->assertDatabaseCount('story_choices', 3);
        $this->assertSame(1, $story->nodes()->where('is_start', true)->count());

        // Removing a node on a subsequent save cascades its choices and nulls the
        // incoming target.
        $payload['nodes'] = array_slice($payload['nodes'], 0, 2); // drop "right"
        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", $payload)->assertOk();

        $this->assertDatabaseCount('story_nodes', 2);
        // start->right choice removed; only start->left and left->null remain.
        $this->assertDatabaseCount('story_choices', 2);
    }

    public function test_graph_save_promotes_a_start_node_when_none_flagged(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->cyoa()->create();

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", [
            'nodes' => [
                ['key' => 'a', 'body' => 'A', 'is_start' => false],
                ['key' => 'b', 'body' => 'B', 'is_start' => false],
            ],
            'choices' => [],
        ])->assertOk();

        $this->assertSame(1, $story->nodes()->where('is_start', true)->count());
    }

    public function test_duplicate_node_keys_are_rejected(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->cyoa()->create();

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", [
            'nodes' => [
                ['key' => 'start', 'body' => 'A', 'is_start' => true],
                ['key' => 'start', 'body' => 'B', 'is_start' => false],
            ],
            'choices' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('nodes.1.key');
    }

    public function test_graph_save_rejected_on_long_form_story(): void
    {
        $owner = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->create(); // long_form

        $this->actingAs($owner)->putJson("/api/stories/{$story->id}/graph", ['nodes' => [], 'choices' => []])
            ->assertStatus(422);
    }
}

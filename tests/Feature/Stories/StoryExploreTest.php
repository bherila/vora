<?php

namespace Tests\Feature\Stories;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Interest;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryExploreTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_lists_only_published_approved_discoverable_stories(): void
    {
        $viewer = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();

        $visible = Story::factory()->for($author)->readable()->create(['title' => 'Visible story']);
        Story::factory()->for($author)->published()->create(['title' => 'Pending review']);
        Story::factory()->for($author)->published()->rejected()->create(['title' => 'Rejected']);
        Story::factory()->for($author)->approved()->create(['title' => 'Draft']);
        Story::factory()->for($author)->readable()->unlisted()->create(['title' => 'Unlisted']);
        Story::factory()->for($author)->readable()->audience(Audience::Followers)->create(['title' => 'Followers only']);

        $this->actingAs($viewer)->getJson('/api/explore/stories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.title', 'Visible story')
            ->assertJsonMissingPath('data.0.moderation_status')
            ->assertJsonMissingPath('data.0.review');
    }

    public function test_explore_stories_filters_by_interest(): void
    {
        $viewer = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $horror = Interest::query()->create(['name' => 'Horror']);
        $comedy = Interest::query()->create(['name' => 'Comedy']);

        $tagged = Story::factory()->for($author)->readable()->create(['title' => 'Haunted']);
        $tagged->interests()->sync([$horror->id]);
        $other = Story::factory()->for($author)->readable()->create(['title' => 'Funny']);
        $other->interests()->sync([$comedy->id]);

        $this->actingAs($viewer)->getJson('/api/explore/stories?interest_ids[]='.$horror->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_explore_stories_excludes_inactive_owners(): void
    {
        $viewer = User::factory()->approved()->create();
        $disabled = User::factory()->approved()->disabled()->create();
        $deactivated = User::factory()->approved()->create();
        $active = User::factory()->approved()->create();

        Story::factory()->for($disabled)->readable()->create(['title' => 'Disabled']);
        Story::factory()->for($deactivated)->readable()->create(['title' => 'Deactivated']);
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        $visible = Story::factory()->for($active)->readable()->create(['title' => 'Visible']);

        $this->actingAs($viewer)->getJson('/api/explore/stories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_explore_does_not_correlate_a_separate_persona_with_its_owner(): void
    {
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Private Human Identity']);
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Public Persona',
            'is_linked' => false,
        ]);
        $story = Story::factory()->for($owner)->readable()->create();
        $story->authors()->where('user_id', $owner->id)->update(['character_id' => $persona->id]);

        $this->actingAs($viewer)->getJson('/api/explore/stories')
            ->assertOk()
            ->assertJsonPath('data.0.owner.id', null)
            ->assertJsonPath('data.0.owner.display_name', 'Public Persona')
            ->assertJsonPath('data.0.authors.0.display_name', 'Public Persona')
            ->assertJsonMissingPath('data.0.authors.0.user_id')
            ->assertJsonMissingPath('data.0.authors.0.character_id');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\RecentProfileVisit;
use App\Models\User;
use App\Services\Profile\RecentProfileTrail;
use App\Support\ActiveIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SideRailTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->approved()->create(); // id 1 is always admin
        $this->viewer = User::factory()->approved()->create();
    }

    #[Test]
    public function ordinary_profile_and_persona_pages_record_live_gated_private_cards(): void
    {
        $person = User::factory()->approved()->create(['display_name' => 'Visible Person']);
        $owner = User::factory()->approved()->create([
            'name' => 'Owner Correlation Sentinel',
            'display_name' => 'Owner Correlation Sentinel',
            'profile_audience' => Audience::SpecificPeople,
        ]);
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Independent Persona',
            'is_linked' => false,
        ]);

        $this->actingAs($this->viewer)->get("/users/{$person->id}")->assertOk();
        $this->get("/c/{$persona->ulid}")->assertOk();

        $response = $this->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonPath('data.recently_visited.0.type', RecentProfileVisit::TARGET_CHARACTER)
            ->assertJsonPath('data.recently_visited.0.display_name', 'Independent Persona')
            ->assertJsonPath('data.recently_visited.0.href', "/c/{$persona->ulid}")
            ->assertJsonPath('data.recently_visited.1.display_name', 'Visible Person');

        $content = $response->getContent();
        $this->assertStringNotContainsString('Owner Correlation Sentinel', $content);
        $this->assertStringNotContainsString("/users/{$owner->id}", $content);
        $this->assertDatabaseCount('recent_profile_visits', 2);
    }

    #[Test]
    public function a_target_that_becomes_hidden_disappears_and_is_not_replayed(): void
    {
        $target = User::factory()->approved()->create();
        $this->actingAs($this->viewer)->get("/users/{$target->id}")->assertOk();

        $target->forceFill(['profile_audience' => Audience::SpecificPeople])->save();

        $this->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonCount(0, 'data.recently_visited');
        $this->assertDatabaseCount('recent_profile_visits', 0);
    }

    #[Test]
    public function admin_view_as_self_and_owned_persona_visits_never_write(): void
    {
        $target = User::factory()->approved()->create();
        $admin = User::factory()->approved()->create(['is_admin' => true]);
        $ownedPersona = Character::factory()->for($this->viewer)->create();

        $this->actingAs($admin)->get("/users/{$target->id}")->assertOk();
        $this->actingAs($this->viewer)->get('/me')->assertOk();
        $this->get("/c/{$ownedPersona->ulid}")->assertOk();
        $this->withSession([ActiveIdentity::SESSION_KEY => $ownedPersona->id])
            ->get("/c/{$ownedPersona->ulid}?view_as=public")
            ->assertOk();

        $this->assertDatabaseCount('recent_profile_visits', 0);
    }

    #[Test]
    public function trail_caps_and_expires_and_can_be_cleared(): void
    {
        $old = User::factory()->approved()->create();
        RecentProfileVisit::query()->create([
            'viewer_user_id' => $this->viewer->id,
            'target_type' => RecentProfileVisit::TARGET_USER,
            'target_id' => $old->id,
            'visited_at' => now()->subDays(31),
        ]);

        foreach (User::factory()->approved()->count(11)->create() as $offset => $target) {
            RecentProfileVisit::query()->create([
                'viewer_user_id' => $this->viewer->id,
                'target_type' => RecentProfileVisit::TARGET_USER,
                'target_id' => $target->id,
                'visited_at' => now()->subMinutes(11 - $offset),
            ]);
        }

        $this->actingAs($this->viewer)->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonCount(10, 'data.recently_visited');
        $this->assertDatabaseCount('recent_profile_visits', RecentProfileTrail::MAX_ENTRIES);

        $this->deleteJson('/api/side-rail/history')->assertOk();
        $this->assertDatabaseCount('recent_profile_visits', 0);
    }

    #[Test]
    public function visits_with_the_same_timestamp_use_the_latest_record_first(): void
    {
        $first = User::factory()->approved()->create(['display_name' => 'First Visit']);
        $second = User::factory()->approved()->create(['display_name' => 'Second Visit']);
        $visitedAt = now();

        foreach ([$first, $second] as $target) {
            RecentProfileVisit::query()->create([
                'viewer_user_id' => $this->viewer->id,
                'target_type' => RecentProfileVisit::TARGET_USER,
                'target_id' => $target->id,
                'visited_at' => $visitedAt,
            ]);
        }

        $this->actingAs($this->viewer)->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonPath('data.recently_visited.0.display_name', 'Second Visit')
            ->assertJsonPath('data.recently_visited.1.display_name', 'First Visit');
    }

    #[Test]
    public function live_gated_trail_is_exported_and_soft_delete_clears_it(): void
    {
        $target = User::factory()->approved()->create(['display_name' => 'Exported Visit']);
        $this->actingAs($this->viewer)->get("/users/{$target->id}")->assertOk();

        $this->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonPath('data.recently_visited.0.display_name', 'Exported Visit');

        $this->postJson('/api/account/delete')->assertOk();
        $this->assertDatabaseCount('recent_profile_visits', 0);
    }

    #[Test]
    public function side_rail_is_caller_private_and_suggestions_skip_existing_edges(): void
    {
        $suggested = User::factory()->approved()->create(['display_name' => 'Suggested Person']);
        $known = User::factory()->approved()->create(['display_name' => 'Already Followed']);
        $this->viewer->sentFollowRequests()->create([
            'recipient_id' => $known->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $response = $this->actingAs($this->viewer)->getJson('/api/side-rail')
            ->assertOk();

        $this->assertContains('Suggested Person', collect($response->json('data.suggested_people'))->pluck('display_name'));
        $this->assertStringNotContainsString('Already Followed', $response->getContent());
        $this->assertSame(3, count($response->json('data.pending_actions')));
        $this->assertSame(
            '/users/follow-requests',
            collect($response->json('data.pending_actions'))->firstWhere('label', 'Co-author invites')['href'],
        );
    }
}

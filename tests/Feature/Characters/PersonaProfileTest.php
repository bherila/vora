<?php

namespace Tests\Feature\Characters;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The persona public page (/c/{ulid}) and its content endpoints. The privacy
 * property under test throughout: access is gated on the character's OWN
 * audience, "hidden" is indistinguishable from "missing" (no existence
 * oracle), and a Separate persona's payloads never carry anything that
 * resolves to the human behind it.
 */
class PersonaProfileTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    /**
     * Decode the hydrated initial-data JSON out of a rendered page.
     *
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> */
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_persona_page_renders_for_any_signed_in_viewer(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['display_name' => 'Kira']);

        $data = $this->initialData(
            $this->actingAs($viewer)->get("/c/{$character->ulid}")->assertOk()->getContent(),
        );

        $this->assertSame('Kira', $data['personaProfile']['display_name']);
        $this->assertSame($character->ulid, $data['personaProfile']['ulid']);
        $this->assertFalse($data['personaProfile']['is_owner']);
        $this->assertTrue($data['personaProfile']['can_report']);
    }

    public function test_missing_and_hidden_personas_answer_the_same_generic_404(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $hidden = Character::factory()->for($owner)->audience(Audience::Followers)->create();

        $missing = $this->actingAs($stranger)->get('/c/'.((string) Str::ulid()));
        $missing->assertNotFound();

        // The privacy property: a hidden persona is a 404 (never a 403), with
        // the same generic message — "exists but you can't see it" would be a
        // deanonymization oracle for a Separate persona.
        $restricted = $this->actingAs($stranger)->get("/c/{$hidden->ulid}");
        $restricted->assertNotFound();
        $this->assertSame(
            $missing->exception?->getMessage(),
            $restricted->exception?->getMessage(),
        );
        $this->assertStringNotContainsString($hidden->display_name, $restricted->getContent());
    }

    public function test_owner_and_admitted_followers_see_a_restricted_persona(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $this->follow($follower, $owner);
        $character = Character::factory()->for($owner)->audience(Audience::Followers)->create();

        $this->actingAs($owner)->get("/c/{$character->ulid}")->assertOk();
        $this->actingAs($follower)->get("/c/{$character->ulid}")->assertOk();
    }

    public function test_soft_deleted_persona_404s_even_for_its_owner(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();
        $character->delete();

        $this->actingAs($owner)->get("/c/{$character->ulid}")->assertNotFound();
    }

    public function test_persona_of_a_deactivated_or_unapproved_owner_404s(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();

        $deactivated = User::factory()->approved()->create(['deactivated_at' => now()]);
        $dormant = Character::factory()->for($deactivated)->create();
        $this->actingAs($viewer)->get("/c/{$dormant->ulid}")->assertNotFound();

        $unapproved = User::factory()->create(['approved_at' => null]);
        $pending = Character::factory()->for($unapproved)->create();
        $this->actingAs($viewer)->get("/c/{$pending->ulid}")->assertNotFound();
    }

    public function test_separate_persona_page_carries_nothing_about_the_owner(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create([
            'name' => 'Zebulon Distinctive',
            'display_name' => 'Zebulon Distinctive',
        ]);
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create([
            'display_name' => 'Vex',
            'is_linked' => false,
        ]);

        $html = $this->actingAs($viewer)->get("/c/{$character->ulid}")->assertOk()->getContent();
        $data = $this->initialData($html);

        $this->assertNull($data['personaProfile']['owner']);
        $this->assertFalse($data['personaProfile']['is_linked']);
        // Belt and braces: the owner's name (and email) must not appear anywhere
        // in the hydrated page.
        $this->assertStringNotContainsString('Zebulon', $html);
        $this->assertStringNotContainsString($owner->email, $html);
    }

    public function test_linked_persona_page_names_the_owner_as_page_meta(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Ben']);
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['is_linked' => true]);

        $data = $this->initialData($this->actingAs($viewer)->get("/c/{$character->ulid}")->assertOk()->getContent());
        $this->assertSame('Ben', $data['personaProfile']['owner']['display_name']);
        $this->assertSame("/users/{$owner->id}", $data['personaProfile']['owner']['href']);

        // The owner's own profile page 404s on self, so their link points home.
        $ownData = $this->initialData($this->actingAs($owner)->get("/c/{$character->ulid}")->assertOk()->getContent());
        $this->assertSame('/me', $ownData['personaProfile']['owner']['href']);
        $this->assertTrue($ownData['personaProfile']['is_owner']);
    }

    public function test_undiscoverable_persona_is_direct_link_only(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['discoverable' => false]);

        // Direct link still works (audience admits any signed-in viewer)…
        $this->actingAs($viewer)->get("/c/{$character->ulid}")->assertOk();

        // …but no discovery surface lists it.
        $this->actingAs($viewer)->getJson('/api/explore/personas')
            ->assertOk()->assertJsonMissing(['ulid' => $character->ulid]);
        $directory = $this->initialData($this->actingAs($viewer)->get('/users')->assertOk()->getContent());
        $this->assertSame([], $directory['followDirectoryPersonas']);
    }

    public function test_explore_lists_only_discoverable_personas_of_active_approved_owners(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        $listed = Character::factory()->for($owner)->create();
        Character::factory()->for($owner)->audience(Audience::Followers)->create();
        Character::factory()->for($owner)->create(['discoverable' => false]);
        $trashed = Character::factory()->for($owner)->create();
        $trashed->delete();
        $inactiveOwner = User::factory()->approved()->create(['deactivated_at' => now()]);
        Character::factory()->for($inactiveOwner)->create();

        $response = $this->actingAs($viewer)->getJson('/api/explore/personas')->assertOk();
        $this->assertSame([$listed->ulid], array_column($response->json('data'), 'ulid'));
        $this->assertSame("/c/{$listed->ulid}", $response->json('data.0.href'));
    }

    public function test_explore_interest_filter_matches_only_a_personas_own_ratings(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $interest = Interest::query()->create(['name' => 'Cartography']);

        $rated = Character::factory()->for($owner)->create(['inherit_interests' => false]);
        InterestRating::query()->create([
            'user_id' => $owner->id,
            'character_id' => $rated->id,
            'interest_id' => $interest->id,
            'level' => 2,
        ]);

        // The owner rates the interest on their own profile; an inheriting
        // persona must NOT match through that — inherited matches would surface
        // the owner's interest fingerprint on a pseudonymous card.
        Character::factory()->for($owner)->create(['inherit_interests' => true]);
        InterestRating::query()->create([
            'user_id' => $owner->id,
            'interest_id' => $interest->id,
            'level' => 2,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson('/api/explore/personas?interest_ids[]='.$interest->id)
            ->assertOk();
        $this->assertSame([$rated->ulid], array_column($response->json('data'), 'ulid'));
    }

    public function test_directory_lists_discoverable_personas_without_owner_references(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Quintessa Hiddenowner']);
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create([
            'display_name' => 'Vex',
            'is_linked' => false,
        ]);

        $data = $this->initialData($this->actingAs($viewer)->get('/users')->assertOk()->getContent());

        $personas = $data['followDirectoryPersonas'];
        $this->assertCount(1, $personas);
        $this->assertSame('Vex', $personas[0]['display_name']);
        $this->assertSame("/c/{$character->ulid}", $personas[0]['href']);
        // The persona card never carries the human behind it.
        $this->assertArrayNotHasKey('user_id', $personas[0]);
        $this->assertArrayNotHasKey('owner', $personas[0]);
        $this->assertStringNotContainsString(
            'Hiddenowner',
            json_encode($personas, JSON_THROW_ON_ERROR),
        );
    }

    public function test_content_endpoints_gate_exactly_like_the_page(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $this->follow($follower, $owner);
        $character = Character::factory()->for($owner)->audience(Audience::Followers)->create();

        foreach (['media', 'stories', 'posts', 'counts'] as $endpoint) {
            $this->actingAs($stranger)->getJson("/api/c/{$character->ulid}/{$endpoint}")->assertNotFound();
            $this->actingAs($follower)->getJson("/api/c/{$character->ulid}/{$endpoint}")->assertOk();
        }
    }

    public function test_persona_page_follow_contract_omits_edge_and_owner_identity(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create(['display_name' => 'Private Human Identity']);
        $accountFollower = User::factory()->approved()->create();
        $personaFollower = User::factory()->approved()->create(['display_name' => 'Persona Follower']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Kira',
            'is_linked' => false,
        ]);

        FollowRequest::query()->create([
            'requester_id' => $accountFollower->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => null,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        FollowRequest::query()->create([
            'requester_id' => $personaFollower->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $persona->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->actingAs($viewer)->get("/c/{$persona->ulid}")
            ->assertOk()
            ->assertSee('"id":'.$persona->id, false)
            ->assertDontSee('Private Human Identity');

        $this->getJson("/api/characters/{$persona->id}/followers")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.viewer_is_following', false)
            ->assertJsonPath('data.followers.0.follower.id', $personaFollower->id)
            ->assertJsonMissingPath('data.followers.0.target')
            ->assertJsonMissing(['id' => $owner->id])
            ->assertJsonMissing(['display_name' => 'Private Human Identity']);
    }

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
        });
    }

    public function test_media_tab_lists_only_the_personas_approved_ready_media(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        $shown = Media::factory()->for($owner)->approved()->create(['character_id' => $character->id]);
        Media::factory()->for($owner)->create(['character_id' => $character->id]); // pending review
        Media::factory()->for($owner)->approved()->create(); // owner's own, not the persona's

        $response = $this->actingAs($viewer)->getJson("/api/c/{$character->ulid}/media")->assertOk();
        $this->assertSame([$shown->id], array_column($response->json('data'), 'id'));

        $counts = $this->actingAs($viewer)->getJson("/api/c/{$character->ulid}/counts")->assertOk();
        $this->assertSame(1, $counts->json('data.media'));
    }

    public function test_posts_tab_bylines_the_persona_and_never_the_human(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Hidden Human']);
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['display_name' => 'Kira']);
        Post::factory()->for($owner)->approved()->create(['character_id' => $character->id]);

        $response = $this->actingAs($viewer)->getJson("/api/c/{$character->ulid}/posts")->assertOk();

        $this->assertNull($response->json('data.0.author'));
        $this->assertSame('Kira', $response->json('data.0.as_character.display_name'));
        $this->assertSame($character->ulid, $response->json('data.0.as_character.ulid'));
        $this->assertStringNotContainsString('Hidden Human', $response->getContent());
    }

    public function test_stories_tab_presents_the_owner_as_the_persona(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Hidden Human']);
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create(['display_name' => 'Kira']);

        $story = Story::factory()->for($owner)->published()->approved()->create();
        // Authored *as* the persona: the owner's authorship row carries the character.
        $story->authors()->where('user_id', $owner->id)->update(['character_id' => $character->id]);
        // A story the owner wrote as their main identity must not appear here.
        Story::factory()->for($owner)->published()->approved()->create();

        $response = $this->actingAs($viewer)->getJson("/api/c/{$character->ulid}/stories")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($story->ulid, $response->json('data.0.ulid'));
        // The byline resolves to the persona; raw author user ids are stripped.
        $this->assertSame('Kira', $response->json('data.0.owner.display_name'));
        $this->assertNull($response->json('data.0.owner.id'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0.authors.0'));
        $this->assertStringNotContainsString('Hidden Human', $response->getContent());
    }
}

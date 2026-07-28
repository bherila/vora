<?php

namespace Tests\Feature\Profile;

use App\Models\Character;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The profile-as-container surfaces: /me renders the owner's own profile in
 * editable mode, and a visitor's profile payload carries the character strip
 * plus the viewer's own favorited state.
 */
class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });
    }

    public function test_me_hydrates_the_owner_profile_in_editable_mode(): void
    {
        $user = User::factory()->approved()->create([
            'bio' => 'Owner biography.',
            'pronouns' => 'she/her',
        ]);

        $response = $this->actingAs($user)->get('/me')->assertOk();
        // Hydrated JSON (not HTML-escaped) carries owner mode + the editable block.
        $response->assertSee('"is_self":true', false);
        $response->assertSee('profileEditable', false);

        $this->assertMatchesRegularExpression(
            '/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s',
            $response->getContent(),
        );
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $response->getContent(), $matches);

        /** @var array<string, mixed> $data */
        $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Owner biography.', $data['profileEditable']['bio']);
        $this->assertSame('she/her', $data['profileEditable']['pronouns']);
    }

    /**
     * Personas are opt-in: a user who has never created one must see no persona
     * affordances hydrated into /me — no identity counts, no character records —
     * and /me no longer carries the feed onboarding checklist (it lives on /feed).
     */
    public function test_me_hydrates_no_persona_affordances_for_a_persona_free_user(): void
    {
        $user = User::factory()->approved()->create();

        $data = $this->initialData($this->actingAs($user)->get('/me')->assertOk()->getContent());

        $this->assertSame([], $data['followProfile']['characters']);
        $this->assertSame([], $data['profileCharacters']);
        $this->assertNull($data['profileIdentityCounts']);
        $this->assertArrayNotHasKey('feedOnboarding', $data);
    }

    public function test_me_hydrates_per_identity_content_totals_once_a_persona_exists(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        $character = Character::factory()->for($user)->create();

        Media::factory()->for($user)->approved()->create();
        Media::factory()->for($user)->approved()->create(['character_id' => $character->id]);
        Post::factory()->for($user)->approved()->create();

        $data = $this->initialData($this->actingAs($user)->get('/me')->assertOk()->getContent());

        $this->assertSame(2, $data['profileIdentityCounts']['self']); // media + post
        $this->assertSame(1, $data['profileIdentityCounts']['characters'][(string) $character->id]);
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

    public function test_profile_payload_exposes_the_character_strip_and_viewer_favorite_state(): void
    {
        $this->fakeStorage();
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        Character::factory()->for($owner)->create(['display_name' => 'Aria']);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.is_self', false)
            ->assertJsonPath('data.characters.0.display_name', 'Aria')
            ->assertJsonPath('data.viewer_favorited', false);

        Favorite::query()->create([
            'user_id' => $viewer->id,
            'favoritable_type' => $owner->getMorphClass(),
            'favoritable_id' => $owner->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_favorited', true);
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\FileStorageService;
use App\Support\ActiveIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ViewAsProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_preview_hydrates_the_real_public_and_follower_profile_results(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create([
            'display_name' => 'Owner account',
            'profile_audience' => Audience::Followers,
        ]);

        $public = $this->initialData(
            $this->actingAs($owner)->get('/me?view_as=public')->assertOk()->getContent(),
        );
        $this->assertTrue($public['followProfile']['restricted']);
        $this->assertFalse($public['followProfile']['is_self']);
        $this->assertSame('public', $public['profileViewAs']['mode']);

        $follower = $this->initialData(
            $this->actingAs($owner)->get('/me?view_as=follower')->assertOk()->getContent(),
        );
        $this->assertFalse($follower['followProfile']['restricted']);
        $this->assertFalse($follower['followProfile']['is_self']);
        $this->assertSame('follower', $follower['profileViewAs']['mode']);
    }

    public function test_real_preview_apis_apply_the_audience_matrix_for_every_identity_type(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create(['profile_audience' => Audience::Everyone]);
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);

        foreach ([null, $linked, $separate] as $identity) {
            $media = collect(Audience::cases())->mapWithKeys(function (Audience $audience) use ($owner, $identity): array {
                $item = Media::factory()->for($owner)->approved()->audience($audience)->create([
                    'character_id' => $identity?->id,
                    'title' => $audience->value,
                ]);

                return [$audience->value => $item];
            });

            foreach (['public', 'follower'] as $mode) {
                $request = $this->withSession([ActiveIdentity::SESSION_KEY => $identity?->id])
                    ->actingAs($owner);
                $url = $identity instanceof Character
                    ? "/api/c/{$identity->ulid}/media?view_as={$mode}"
                    : "/api/users/{$owner->id}/media?view_as={$mode}";
                $response = $request->getJson($url)->assertOk();
                $expected = [$media[Audience::Everyone->value]->id];
                if ($mode === 'follower') {
                    $expected[] = $media[Audience::Followers->value]->id;
                }

                $this->assertEqualsCanonicalizing(
                    $expected,
                    collect($response->json('data'))->pluck('id')->all(),
                    "{$mode} API gate diverged for identity ".($identity?->id ?? 'human').'.',
                );
            }
        }
    }

    public function test_preview_post_payloads_and_cards_cannot_offer_mutations(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $this->actingAs($owner)
            ->getJson("/api/users/{$owner->id}/posts?view_as=public")
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.can_report', false);
    }

    public function test_persona_preview_uses_the_active_identity_and_the_persona_visitor_payload(): void
    {
        $this->fakeStorage();
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Private owner']);
        $persona = Character::factory()->for($owner)->audience(Audience::Followers)->create([
            'display_name' => 'Separate persona',
            'is_linked' => false,
        ]);
        $followerMedia = Media::factory()->for($owner)->approved()->audience(Audience::Followers)->create([
            'character_id' => $persona->id,
        ]);
        Media::factory()->for($owner)->approved()->audience(Audience::Mutuals)->create([
            'character_id' => $persona->id,
        ]);

        $page = $this->withSession([ActiveIdentity::SESSION_KEY => $persona->id])
            ->actingAs($owner)
            ->get('/me?view_as=follower')
            ->assertOk();
        $payload = $this->initialData($page->getContent());

        $this->assertArrayNotHasKey('followProfile', $payload);
        $this->assertSame('Separate persona', $payload['personaProfile']['display_name']);
        $this->assertNull($payload['personaProfile']['owner']);
        $this->assertFalse($payload['personaProfile']['is_owner']);
        $this->assertFalse($payload['personaProfile']['can_report']);
        $this->assertSame('follower', $payload['profileViewAs']['mode']);

        $this->withSession([ActiveIdentity::SESSION_KEY => $persona->id])
            ->actingAs($owner)
            ->getJson("/api/c/{$persona->ulid}/media?view_as=follower")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $followerMedia->id);
    }

    public function test_persona_preview_page_requires_the_same_persona_audience_as_the_public_route(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $everyone = Character::factory()->for($owner)->audience(Audience::Everyone)->create();
        $followers = Character::factory()->for($owner)->audience(Audience::Followers)->create();
        $mutuals = Character::factory()->for($owner)->audience(Audience::Mutuals)->create();
        $specific = Character::factory()->for($owner)->audience(Audience::SpecificPeople)->create();

        $this->withSession([ActiveIdentity::SESSION_KEY => $everyone->id])
            ->actingAs($owner)
            ->get('/me?view_as=public')
            ->assertOk();
        $this->withSession([ActiveIdentity::SESSION_KEY => $followers->id])
            ->actingAs($owner)
            ->get('/me?view_as=follower')
            ->assertOk();

        foreach ([
            [$followers, 'public'],
            [$mutuals, 'follower'],
            [$specific, 'follower'],
        ] as [$persona, $mode]) {
            $this->withSession([ActiveIdentity::SESSION_KEY => $persona->id])
                ->actingAs($owner)
                ->get("/me?view_as={$mode}")
                ->assertNotFound();
        }
    }

    public function test_view_as_is_owner_only_identity_bound_and_never_changes_the_session_identity(): void
    {
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create();
        $otherPersona = Character::factory()->for($other)->create();

        $this->actingAs($owner)->get('/me?view_as=unknown')->assertNotFound();
        $this->actingAs($other)->get("/users/{$owner->id}?view_as=public")->assertNotFound();
        $this->actingAs($other)
            ->getJson("/api/users/{$owner->id}/media?view_as=public")
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        $this->withSession([ActiveIdentity::SESSION_KEY => $persona->id])
            ->actingAs($owner)
            ->getJson("/api/c/{$otherPersona->ulid}/media?view_as=public")
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        $this->withSession([ActiveIdentity::SESSION_KEY => $persona->id])
            ->actingAs($owner)
            ->get('/me?view_as=public')
            ->assertOk();
        $this->assertSame($persona->id, session(ActiveIdentity::SESSION_KEY));
    }

    /**
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example/view');
        });
    }
}

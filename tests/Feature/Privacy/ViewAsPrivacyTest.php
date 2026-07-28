<?php

namespace Tests\Feature\Privacy;

use App\Enums\Audience;
use App\Enums\ViewAsMode;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\Privacy\ProfileGate;
use App\Services\Privacy\ViewAsContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewAsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_one_way_follower_profiles_use_the_real_profile_gate(): void
    {
        User::factory()->create(); // Keep the simulated viewer away from the id=1 admin convention.
        $owner = User::factory()->approved()->create();
        $context = app(ViewAsContext::class);
        $gate = app(ProfileGate::class);

        foreach (Audience::cases() as $audience) {
            $owner->forceFill(['profile_audience' => $audience])->save();

            $public = $context->simulate(ViewAsMode::Public, $owner);
            $this->assertSame(
                $audience === Audience::Everyone,
                $gate->canView($public, $owner),
                "Public profile simulation diverged for {$audience->value}.",
            );

            $follower = $context->simulate(ViewAsMode::Follower, $owner);
            $this->assertSame(
                in_array($audience, [Audience::Everyone, Audience::Followers], true),
                $gate->canView($follower, $owner),
                "Follower profile simulation diverged for {$audience->value}.",
            );
        }
    }

    public function test_boolean_and_query_gates_match_for_human_linked_and_separate_identities(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);
        $context = app(ViewAsContext::class);

        foreach ([null, $linked, $separate] as $identity) {
            $items = collect(Audience::cases())->mapWithKeys(function (Audience $audience) use ($owner, $identity): array {
                $media = Media::factory()->for($owner)->approved()->audience($audience)->create([
                    'character_id' => $identity?->id,
                ]);

                return [$audience->value => $media];
            });

            foreach (ViewAsMode::cases() as $mode) {
                $viewer = $context->simulate($mode, $owner, $identity);
                $queryIds = Media::query()->viewableBy($viewer)->pluck('id')->map('intval')->all();

                foreach ($items as $audience => $media) {
                    $expected = $audience === Audience::Everyone->value
                        || ($mode === ViewAsMode::Follower && $audience === Audience::Followers->value);

                    $this->assertSame(
                        $expected,
                        $media->isViewableBy($viewer),
                        "{$mode->value} boolean gate diverged for {$audience} on identity ".($identity?->id ?? 'human').'.',
                    );
                    $this->assertSame(
                        $expected,
                        in_array($media->id, $queryIds, true),
                        "{$mode->value} query gate diverged for {$audience} on identity ".($identity?->id ?? 'human').'.',
                    );
                }
            }
        }
    }

    public function test_account_follower_simulation_subsumes_only_linked_personas(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);
        $linkedMedia = Media::factory()->for($owner)->approved()->audience(Audience::Followers)->create([
            'character_id' => $linked->id,
        ]);
        $separateMedia = Media::factory()->for($owner)->approved()->audience(Audience::Followers)->create([
            'character_id' => $separate->id,
        ]);

        $viewer = app(ViewAsContext::class)->simulate(ViewAsMode::Follower, $owner);

        $this->assertTrue($linkedMedia->isViewableBy($viewer));
        $this->assertFalse($separateMedia->isViewableBy($viewer));
        $this->assertSame(
            [$linkedMedia->id],
            Media::query()->viewableBy($viewer)->pluck('id')->map('intval')->all(),
        );
    }
}

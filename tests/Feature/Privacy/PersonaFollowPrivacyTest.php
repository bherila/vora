<?php

namespace Tests\Feature\Privacy;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use App\Services\Privacy\ProfileGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonaFollowPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $owner, ?Character $character = null): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $owner->id,
            'recipient_character_id' => $character?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    public function test_persona_follow_scope_matches_record_checks_for_every_character_context(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $accountFollower = User::factory()->approved()->create();
        $personaFollower = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->audience(Audience::Followers)->create([
            'is_linked' => true,
        ]);
        $separate = Character::factory()->for($owner)->audience(Audience::Followers)->create([
            'is_linked' => false,
        ]);
        $this->follow($accountFollower, $owner);
        $this->follow($personaFollower, $owner, $separate);

        $ownerMedia = Media::factory()->for($owner)->audience(Audience::Followers)->create();
        $linkedMedia = Media::factory()->for($owner)->audience(Audience::Followers)->create([
            'character_id' => $linked->id,
        ]);
        $separateMedia = Media::factory()->for($owner)->audience(Audience::Followers)->create([
            'character_id' => $separate->id,
        ]);
        $ownerPost = Post::factory()->for($owner)->audience(Audience::Followers)->create();
        $linkedPost = Post::factory()->for($owner)->audience(Audience::Followers)->create([
            'character_id' => $linked->id,
        ]);
        $separatePost = Post::factory()->for($owner)->audience(Audience::Followers)->create([
            'character_id' => $separate->id,
        ]);
        $story = Story::factory()->for($owner)->create(['audience' => Audience::Followers]);

        $this->assertTrue($ownerMedia->isViewableBy($accountFollower));
        $this->assertTrue($linkedMedia->isViewableBy($accountFollower));
        $this->assertFalse($separateMedia->isViewableBy($accountFollower));
        $this->assertTrue($ownerPost->isViewableBy($accountFollower));
        $this->assertTrue($linkedPost->isViewableBy($accountFollower));
        $this->assertFalse($separatePost->isViewableBy($accountFollower));
        $this->assertTrue($story->isViewableBy($accountFollower));
        $this->assertTrue($linked->isViewableBy($accountFollower));
        $this->assertFalse($separate->isViewableBy($accountFollower));

        $this->assertFalse($ownerMedia->isViewableBy($personaFollower));
        $this->assertFalse($linkedMedia->isViewableBy($personaFollower));
        $this->assertTrue($separateMedia->isViewableBy($personaFollower));
        $this->assertFalse($ownerPost->isViewableBy($personaFollower));
        $this->assertFalse($linkedPost->isViewableBy($personaFollower));
        $this->assertTrue($separatePost->isViewableBy($personaFollower));
        $this->assertFalse($story->isViewableBy($personaFollower));
        $this->assertFalse($linked->isViewableBy($personaFollower));
        $this->assertTrue($separate->isViewableBy($personaFollower));

        $this->assertEqualsCanonicalizing(
            [$ownerMedia->id, $linkedMedia->id],
            Media::query()->viewableBy($accountFollower)->pluck('id')->all(),
        );
        $this->assertSame(
            [$separateMedia->id],
            Media::query()->viewableBy($personaFollower)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ownerPost->id, $linkedPost->id],
            Post::query()->viewableBy($accountFollower)->pluck('id')->all(),
        );
        $this->assertSame(
            [$separatePost->id],
            Post::query()->viewableBy($personaFollower)->pluck('id')->all(),
        );
        $this->assertSame(
            [$story->id],
            Story::query()->viewableBy($accountFollower)->pluck('id')->all(),
        );
        $this->assertSame([], Story::query()->viewableBy($personaFollower)->pluck('id')->all());
        $this->assertSame(
            [$linked->id],
            Character::query()->viewableBy($accountFollower)->pluck('id')->all(),
        );
        $this->assertSame(
            [$separate->id],
            Character::query()->viewableBy($personaFollower)->pluck('id')->all(),
        );
    }

    public function test_persona_only_edge_never_grants_human_profile_access_or_depends_on_active_identity(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create([
            'profile_audience' => Audience::Followers,
        ]);
        $viewer = User::factory()->approved()->create();
        $activeIdentity = Character::factory()->for($viewer)->create();
        $followedPersona = Character::factory()->for($owner)->create(['is_linked' => false]);
        $this->follow($viewer, $owner, $followedPersona);
        $mutualTarget = User::factory()->approved()->create([
            'profile_audience' => Audience::Mutuals,
        ]);
        $this->follow($viewer, $mutualTarget);
        $this->follow($mutualTarget, $viewer, $activeIdentity);
        $gate = app(ProfileGate::class);

        $this->assertFalse($gate->canView($viewer, $owner));
        $this->assertFalse($gate->canViewMany($viewer, collect([$owner]))[$owner->id]);
        $this->assertFalse($gate->canView($viewer, $mutualTarget));
        $this->assertFalse($gate->canViewMany($viewer, collect([$mutualTarget]))[$mutualTarget->id]);

        session(['active_character_id' => $activeIdentity->id]);

        $this->assertFalse($gate->canView($viewer, $owner));
        $this->assertFalse($gate->canViewMany($viewer, collect([$owner]))[$owner->id]);
        $this->assertFalse($gate->canView($viewer, $mutualTarget));
        $this->assertFalse($gate->canViewMany($viewer, collect([$mutualTarget]))[$mutualTarget->id]);
    }
}

<?php

namespace Tests\Feature\Privacy;

use App\Enums\Audience;
use App\Models\AudienceMember;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the shared HasPrivacyPolicy engine through Media. The same trait
 * backs Story and any future content, so proving the four tiers, the
 * no-link-bypass guarantee, and scope/record parity here covers them all.
 */
class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** Record an accepted follow: $follower follows $followee. */
    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    public function test_everyone_audience_is_viewable_by_anyone(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['audience' => Audience::Everyone]);

        $this->assertTrue($media->isViewableBy($viewer));
        $this->assertFalse($media->isViewableBy(null)); // unauthenticated never passes
    }

    public function test_followers_audience_respects_the_follow_edge(): void
    {
        $owner = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $this->follow($follower, $owner);

        $media = Media::factory()->for($owner)->create(['audience' => Audience::Followers]);

        $this->assertTrue($media->isViewableBy($follower), 'a follower may view');
        $this->assertFalse($media->isViewableBy($stranger), 'a non-follower may not');
        $this->assertTrue($media->isViewableBy($owner), 'the owner always may');
        $this->assertTrue($media->isViewableBy($admin), 'an admin always may');
    }

    public function test_mutuals_audience_requires_a_follow_back(): void
    {
        $owner = User::factory()->approved()->create();
        $oneWay = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create();

        // oneWay follows owner but is not followed back.
        $this->follow($oneWay, $owner);
        // mutual and owner follow each other.
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);

        $media = Media::factory()->for($owner)->create(['audience' => Audience::Mutuals]);

        $this->assertFalse($media->isViewableBy($oneWay), 'a one-way follow is not a mutual');
        $this->assertTrue($media->isViewableBy($mutual), 'a reciprocated follow is a mutual');
    }

    public function test_specific_people_audience_respects_the_allowlist(): void
    {
        $owner = User::factory()->approved()->create();
        $granted = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        $media = Media::factory()->for($owner)->create(['audience' => Audience::SpecificPeople]);
        $media->syncAudienceMembers([$granted->id]);

        $this->assertTrue($media->isViewableBy($granted));
        $this->assertFalse($media->isViewableBy($other));
    }

    public function test_a_share_link_never_bypasses_the_audience(): void
    {
        // "unlisted" only flips discoverable off; combined with a Followers
        // audience, possessing the link must NOT let a stranger view it.
        $owner = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();

        $media = Media::factory()->for($owner)->unlisted()->create(['audience' => Audience::Followers]);

        $this->assertFalse($media->discoverable);
        $this->assertFalse($media->isViewableBy($stranger), 'the link grants no access beyond the tier');
    }

    public function test_scope_viewable_by_matches_the_per_record_check(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $owner); // viewer follows owner (not mutual)

        $everyone = Media::factory()->for($owner)->create(['audience' => Audience::Everyone]);
        $followers = Media::factory()->for($owner)->create(['audience' => Audience::Followers]);
        $mutuals = Media::factory()->for($owner)->create(['audience' => Audience::Mutuals]);
        $specific = Media::factory()->for($owner)->create(['audience' => Audience::SpecificPeople]);
        $specific->syncAudienceMembers([$viewer->id]);

        $scoped = Media::query()->viewableBy($viewer)->pluck('id')->all();

        // everyone + followers (viewer follows) + specific (allowlisted); NOT mutuals.
        $this->assertEqualsCanonicalizing([$everyone->id, $followers->id, $specific->id], $scoped);
        $this->assertNotContains($mutuals->id, $scoped);

        // Scope and per-record check must agree for every row.
        foreach ([$everyone, $followers, $mutuals, $specific] as $media) {
            $this->assertSame(
                in_array($media->id, $scoped, true),
                $media->fresh()->isViewableBy($viewer),
            );
        }
    }

    public function test_discoverable_scope_is_strictly_public_and_listed(): void
    {
        $owner = User::factory()->approved()->create();
        $listed = Media::factory()->for($owner)->create(['audience' => Audience::Everyone, 'discoverable' => true]);
        Media::factory()->for($owner)->unlisted()->create(['audience' => Audience::Everyone]); // not listed
        Media::factory()->for($owner)->create(['audience' => Audience::Followers]); // restricted

        $discoverable = Media::query()->discoverable()->pluck('id')->all();

        $this->assertSame([$listed->id], $discoverable);
    }

    public function test_sync_audience_members_returns_the_grant_diff(): void
    {
        $owner = User::factory()->approved()->create();
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        $c = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['audience' => Audience::SpecificPeople]);

        $first = $media->syncAudienceMembers([$a->id, $b->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $first['added']);
        $this->assertSame([], $first['removed']);

        $second = $media->syncAudienceMembers([$b->id, $c->id]);
        $this->assertEqualsCanonicalizing([$c->id], $second['added']);
        $this->assertEqualsCanonicalizing([$a->id], $second['removed']);
        $this->assertSame(2, $media->audienceMembers()->count());
    }

    public function test_allowlist_is_pruned_when_content_is_deleted(): void
    {
        $owner = User::factory()->approved()->create();
        $granted = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['audience' => Audience::SpecificPeople]);
        $media->syncAudienceMembers([$granted->id]);

        $media->delete();

        $this->assertSame(0, AudienceMember::query()->count());
    }

    public function test_allowlist_grant_is_removed_when_the_member_is_erased(): void
    {
        $owner = User::factory()->approved()->create();
        $granted = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['audience' => Audience::SpecificPeople]);
        $media->syncAudienceMembers([$granted->id]);

        $granted->forceDelete();

        $this->assertSame(0, AudienceMember::query()->count(), 'an erased user leaves no allowlist grants');
    }
}

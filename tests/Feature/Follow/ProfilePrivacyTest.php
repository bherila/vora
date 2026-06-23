<?php

namespace Tests\Feature\Follow;

use App\Enums\Audience;
use App\Models\AudienceMember;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePrivacyTest extends TestCase
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

    private function withProfileAudience(Audience $audience): User
    {
        $user = User::factory()->approved()->create(['user_type' => 'human']);
        $user->update(['profile_audience' => $audience]);

        return $user;
    }

    public function test_everyone_profile_is_fully_visible(): void
    {
        $owner = $this->withProfileAudience(Audience::Everyone);
        $viewer = User::factory()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.user_type', 'human');
    }

    public function test_followers_profile_is_a_stub_for_non_followers(): void
    {
        $owner = $this->withProfileAudience(Audience::Followers);
        $viewer = User::factory()->approved()->create();

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.restricted', true)
            ->assertJsonPath('data.user_type', null);

        $this->follow($viewer, $owner);

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.user_type', 'human');
    }

    public function test_mutuals_profile_requires_a_follow_back(): void
    {
        $owner = $this->withProfileAudience(Audience::Mutuals);
        $viewer = User::factory()->approved()->create();
        $this->follow($viewer, $owner); // one-way

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', true);

        $this->follow($owner, $viewer); // now mutual

        $this->actingAs($viewer)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', false);
    }

    public function test_specific_profile_uses_the_allowlist(): void
    {
        $owner = $this->withProfileAudience(Audience::SpecificPeople);
        $granted = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $owner->profileAudienceMembers()->create(['user_id' => $granted->id]);

        $this->actingAs($granted)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', false);
        $this->actingAs($other)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', true);
    }

    public function test_admin_sees_a_restricted_profile_fully(): void
    {
        $owner = $this->withProfileAudience(Audience::Followers);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.user_type', 'human');
    }

    public function test_directory_lists_restricted_profiles_without_details(): void
    {
        $owner = $this->withProfileAudience(Audience::Followers);
        $viewer = User::factory()->approved()->create();

        $row = fn (array $data): array => collect($data)->firstWhere('id', $owner->id);

        $data = $this->actingAs($viewer)->getJson('/api/users')->assertOk()->json('data');
        $found = $row($data);
        $this->assertNotNull($found, 'a restricted profile still appears in the directory');
        $this->assertTrue($found['restricted']);
        $this->assertNull($found['user_type']);

        $this->follow($viewer, $owner);
        $data = $this->actingAs($viewer)->getJson('/api/users')->json('data');
        $found = $row($data);
        $this->assertFalse($found['restricted']);
        $this->assertSame('human', $found['user_type']);
    }

    public function test_directory_masks_restricted_profile_interest_match_data_and_sorting(): void
    {
        $owner = $this->withProfileAudience(Audience::Followers);
        $owner->update(['display_name' => 'Z Restricted']);
        $visible = $this->withProfileAudience(Audience::Everyone);
        $visible->update(['display_name' => 'A Visible']);
        $viewer = User::factory()->approved()->create();
        $shared = Interest::query()->create(['name' => 'Shared']);

        InterestRating::query()->create(['user_id' => $viewer->id, 'interest_id' => $shared->id, 'level' => 5]);
        InterestRating::query()->create(['user_id' => $owner->id, 'interest_id' => $shared->id, 'level' => 5]);

        $data = $this->actingAs($viewer)->getJson('/api/users')->assertOk()->json('data');
        $restrictedRow = collect($data)->firstWhere('id', $owner->id);
        $visibleRow = collect($data)->firstWhere('id', $visible->id);

        $this->assertNotNull($restrictedRow, 'a restricted profile still appears in the directory');
        $this->assertTrue($restrictedRow['restricted']);
        $this->assertNull($restrictedRow['matching_interests_count']);
        $this->assertNull($restrictedRow['interest_match_score']);
        $this->assertNotNull($visibleRow, 'a visible profile still appears in the directory');
        $this->assertLessThan(
            array_search($owner->id, collect($data)->pluck('id')->all(), true),
            array_search($visible->id, collect($data)->pluck('id')->all(), true),
            'restricted profiles must not be promoted by hidden interest matches',
        );
    }

    public function test_purging_a_user_prunes_their_profile_allowlist(): void
    {
        $owner = $this->withProfileAudience(Audience::SpecificPeople);
        $granted = User::factory()->approved()->create();
        $owner->profileAudienceMembers()->create(['user_id' => $granted->id]);
        $this->assertSame(1, AudienceMember::query()->count());

        app(UserAccountService::class)->purge($owner);

        $this->assertSame(0, AudienceMember::query()->count());
    }

    public function test_leaving_the_specific_tier_clears_the_profile_allowlist(): void
    {
        $owner = $this->withProfileAudience(Audience::SpecificPeople);
        $granted = User::factory()->approved()->create();
        $owner->profileAudienceMembers()->create(['user_id' => $granted->id]);

        $this->actingAs($owner)->patchJson('/api/account', [
            'name' => $owner->name,
            'display_name' => $owner->display_name,
            'email' => $owner->email,
            'profile_audience' => Audience::Everyone->value,
        ])->assertOk();

        $this->assertSame(0, AudienceMember::query()->count(), 'stale grants cannot survive a tier change');
    }

    public function test_account_settings_can_set_specific_profile_allowlist(): void
    {
        $owner = $this->withProfileAudience(Audience::Everyone);
        $granted = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();

        $this->actingAs($owner)->patchJson('/api/account', [
            'name' => $owner->name,
            'display_name' => $owner->display_name,
            'email' => $owner->email,
            'profile_audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$granted->id],
        ])->assertOk()
            ->assertJsonPath('data.profile_audience', Audience::SpecificPeople->value)
            ->assertJsonPath('data.audience_user_ids', [$granted->id]);

        $this->actingAs($granted)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', false);
        $this->actingAs($other)->getJson("/api/users/{$owner->id}")
            ->assertJsonPath('data.restricted', true);
    }

    public function test_follow_request_inbox_masks_restricted_requester_details(): void
    {
        // The requester's own profile is followers-only. Create it first so the
        // recipient is not user id 1 (which is always treated as an admin).
        $requester = $this->withProfileAudience(Audience::Followers);
        $recipient = User::factory()->approved()->create();
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        // The recipient does not follow the requester → details masked.
        $row = collect($this->actingAs($recipient)->getJson('/api/users/follow-requests')->json('data'))->first();
        $this->assertTrue($row['requester']['restricted']);
        $this->assertNull($row['requester']['user_type']);

        // Once the recipient follows the requester, the requester's profile opens.
        $this->follow($recipient, $requester);
        $row = collect($this->actingAs($recipient)->getJson('/api/users/follow-requests')->json('data'))->first();
        $this->assertFalse($row['requester']['restricted']);
        $this->assertSame('human', $row['requester']['user_type']);
    }

    public function test_follow_request_can_still_be_sent_to_a_restricted_profile(): void
    {
        $owner = $this->withProfileAudience(Audience::Followers);
        $viewer = User::factory()->approved()->create();

        $this->actingAs($viewer)->postJson("/api/users/{$owner->id}/follow-requests")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertTrue(
            FollowRequest::query()->where('requester_id', $viewer->id)->where('recipient_id', $owner->id)->where('status', 'pending')->exists(),
        );
    }
}

<?php

namespace Tests\Feature\Follow;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\User;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class FollowRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_only_shows_mutual_interests_by_default(): void
    {
        $current = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $mutual = Interest::query()->create(['name' => 'Mutual']);
        $hidden = Interest::query()->create(['name' => 'Hidden']);

        InterestRating::query()->create(['user_id' => $current->id, 'interest_id' => $mutual->id, 'level' => 3]);
        InterestRating::query()->create(['user_id' => $other->id, 'interest_id' => $mutual->id, 'level' => 3]);
        InterestRating::query()->create(['user_id' => $other->id, 'interest_id' => $hidden->id, 'level' => 3]);

        $this->actingAs($current)->getJson("/api/users/{$other->id}")
            ->assertOk()
            ->assertJsonPath('data.mutual_interests.0.name', 'Mutual')
            ->assertJsonMissing(['name' => 'Hidden']);
    }

    public function test_directory_sorts_users_by_interest_match_score(): void
    {
        $current = User::factory()->approved()->create();
        $best = User::factory()->approved()->create(['display_name' => 'Best Match']);
        $partial = User::factory()->approved()->create(['display_name' => 'Partial Match']);
        $none = User::factory()->approved()->create(['display_name' => 'No Match']);

        $travel = Interest::query()->create(['name' => 'Travel']);
        $art = Interest::query()->create(['name' => 'Art']);
        $gaming = Interest::query()->create(['name' => 'Gaming']);

        InterestRating::query()->create(['user_id' => $current->id, 'interest_id' => $travel->id, 'level' => 5]);
        InterestRating::query()->create(['user_id' => $current->id, 'interest_id' => $art->id, 'level' => 4]);
        InterestRating::query()->create(['user_id' => $current->id, 'interest_id' => $gaming->id, 'level' => -4]);

        InterestRating::query()->create(['user_id' => $best->id, 'interest_id' => $travel->id, 'level' => 5]);
        InterestRating::query()->create(['user_id' => $best->id, 'interest_id' => $art->id, 'level' => 5]);
        InterestRating::query()->create(['user_id' => $partial->id, 'interest_id' => $travel->id, 'level' => 5]);
        InterestRating::query()->create(['user_id' => $none->id, 'interest_id' => $gaming->id, 'level' => 5]);

        $data = $this->actingAs($current)->getJson('/api/users')->assertOk()->json('data');

        $this->assertSame([$best->id, $partial->id, $none->id], collect($data)->pluck('id')->all());
        $this->assertSame(100, $data[0]['interest_match_score']);
        $this->assertSame(2, $data[0]['matching_interests_count']);
        $this->assertSame(50, $data[1]['interest_match_score']);
        $this->assertSame(0, $data[2]['interest_match_score']);
    }

    public function test_profile_rejects_non_discoverable_users(): void
    {
        $current = User::factory()->approved()->create();
        $pending = User::factory()->pendingApproval()->create();
        $disabled = User::factory()->approved()->disabled()->create();

        $this->actingAs($current)->getJson("/api/users/{$pending->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->actingAs($current)->getJson("/api/users/{$disabled->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_users_can_request_accept_and_follow_back_with_audit_logs(): void
    {
        Notification::fake();
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();

        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $followRequest = FollowRequest::query()->where('requester_id', $requester->id)->where('recipient_id', $recipient->id)->firstOrFail();
        Notification::assertSentTo(
            $recipient,
            FollowRequestReceived::class,
            fn (FollowRequestReceived $notification, array $channels): bool => $channels === ['database', WebPushChannel::class],
        );
        $this->assertDatabaseHas('follow_request_audit_logs', ['follow_request_id' => $followRequest->id, 'action' => 'requested']);

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        Notification::assertSentTo(
            $requester,
            FollowRequestAccepted::class,
            fn (FollowRequestAccepted $notification, array $channels): bool => $channels === ['database', WebPushChannel::class],
        );
        $this->assertDatabaseHas('follow_request_audit_logs', ['follow_request_id' => $followRequest->id, 'action' => 'accepted']);
        $this->actingAs($recipient)->getJson("/api/users/{$requester->id}")
            ->assertOk()
            ->assertJsonPath('data.can_follow_back', true);
    }

    public function test_declined_requests_are_rate_limited_for_24_hours_without_notifications(): void
    {
        Notification::fake();
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create(['notify_follow_request' => false]);

        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk();

        $followRequest = FollowRequest::query()
            ->where('requester_id', $requester->id)
            ->where('recipient_id', $recipient->id)
            ->firstOrFail();

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/decline")->assertOk();
        Notification::assertNothingSent();

        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertStatus(429);

        $followRequest->forceFill(['responded_at' => now()->subDay()->subMinute()])->save();
        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk();

        $this->assertSame(3, FollowRequestAuditLog::query()->count());
    }

    public function test_audit_logs_survive_permanent_deletion_of_follow_participants(): void
    {
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);
        $auditLog = FollowRequestAuditLog::query()->create([
            'follow_request_id' => $followRequest->id,
            'actor_id' => $requester->id,
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'action' => 'requested',
        ]);

        app(UserAccountService::class)->purge($requester);

        $this->assertSame(1, FollowRequestAuditLog::query()->count());
        $this->assertDatabaseHas('follow_request_audit_logs', [
            'id' => $auditLog->id,
            'follow_request_id' => null,
            'actor_id' => null,
            'requester_id' => null,
            'recipient_id' => $recipient->id,
        ]);

        app(UserAccountService::class)->purge($recipient);

        $this->assertSame(1, FollowRequestAuditLog::query()->count());
        $this->assertDatabaseHas('follow_request_audit_logs', [
            'id' => $auditLog->id,
            'follow_request_id' => null,
            'requester_id' => null,
            'recipient_id' => null,
        ]);
    }

    public function test_profile_marks_declined_requests_retryable_after_24_hours(): void
    {
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'declined',
            'responded_at' => now()->subHours(23),
        ]);

        $this->actingAs($requester)->getJson("/api/users/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.follow_request.status', 'declined')
            ->assertJsonPath('data.follow_request.can_retry', false);

        $followRequest->forceFill(['responded_at' => now()->subDay()->subMinute()])->save();

        $this->actingAs($requester)->getJson("/api/users/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.follow_request.status', 'declined')
            ->assertJsonPath('data.follow_request.can_retry', true);
    }

    public function test_inbox_and_count_exclude_requests_from_inactive_requesters(): void
    {
        $recipient = User::factory()->approved()->create();
        $active = User::factory()->approved()->create();
        $disabled = User::factory()->approved()->create();
        $deactivated = User::factory()->approved()->create();

        foreach ([$active, $disabled, $deactivated] as $requester) {
            FollowRequest::query()->create([
                'requester_id' => $requester->id,
                'recipient_id' => $recipient->id,
                'status' => 'pending',
            ]);
        }
        $disabled->forceFill(['is_disabled' => true])->save();
        $deactivated->forceFill(['deactivated_at' => now()])->save();

        // Only the active requester's pending request survives in both the list
        // and the badge count — the two must agree.
        $this->actingAs($recipient)->getJson('/api/users/follow-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requester.id', $active->id);

        $this->actingAs($recipient)->getJson('/api/users/follow-requests/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_profile_page_404s_for_non_discoverable_or_self(): void
    {
        $current = User::factory()->approved()->create();
        $disabled = User::factory()->approved()->disabled()->create();

        $this->actingAs($current)->get("/users/{$disabled->id}")->assertNotFound();
        // Viewing your own profile page is not a route the UI offers.
        $this->actingAs($current)->get("/users/{$current->id}")->assertNotFound();
    }

    public function test_profile_page_hydrates_payload_without_an_ajax_round_trip(): void
    {
        $current = User::factory()->approved()->create();
        $other = User::factory()->approved()->create(['display_name' => 'Aria']);

        // The page ships the full payload inline (no follow-up GET on render).
        $this->actingAs($current)->get("/users/{$other->id}")
            ->assertOk()
            ->assertSee('initial-data')
            ->assertSee('"display_name":"Aria"', false)
            ->assertSee("\"id\":{$other->id}", false);
    }

    public function test_navbar_follow_request_count_excludes_inactive_requesters(): void
    {
        $recipient = User::factory()->approved()->create();
        $requester = User::factory()->approved()->create();
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $this->actingAs($recipient)->get('/me')->assertSee('requestCount":1', false);

        $requester->forceFill(['is_disabled' => true])->save();
        $this->actingAs($recipient)->get('/me')->assertSee('requestCount":0', false);
    }

    public function test_request_from_a_disabled_requester_cannot_be_accepted(): void
    {
        $recipient = User::factory()->approved()->create();
        $requester = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $requester->forceFill(['is_disabled' => true])->save();

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/accept")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('follow_requests', ['id' => $followRequest->id, 'status' => 'pending']);
    }

    public function test_human_follow_mechanics_ignore_persona_scoped_edges(): void
    {
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $persona = Character::factory()->for($recipient)->create();
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $persona->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->actingAs($requester)
            ->getJson("/api/users/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.follow_request', null);

        $this->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk()
            ->assertJsonPath('data.status', FollowRequest::STATUS_PENDING);

        $this->assertDatabaseCount('follow_requests', 2);
        $this->assertDatabaseHas('follow_requests', [
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => null,
            'status' => FollowRequest::STATUS_PENDING,
        ]);

        // A reverse persona edge is not a human follow-back relationship.
        $reversePersona = Character::factory()->for($requester)->create();
        FollowRequest::query()->create([
            'requester_id' => $recipient->id,
            'recipient_id' => $requester->id,
            'recipient_character_id' => $reversePersona->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->getJson("/api/users/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.can_follow_back', false);
    }

    public function test_human_request_inbox_and_counts_ignore_persona_scoped_rows(): void
    {
        $recipient = User::factory()->approved()->create();
        $humanRequester = User::factory()->approved()->create();
        $personaRequester = User::factory()->approved()->create();
        $persona = Character::factory()->for($recipient)->create();

        FollowRequest::query()->create([
            'requester_id' => $humanRequester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => null,
            'status' => FollowRequest::STATUS_PENDING,
        ]);
        $personaRequest = FollowRequest::query()->create([
            'requester_id' => $personaRequester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $persona->id,
            'status' => FollowRequest::STATUS_PENDING,
        ]);

        $this->actingAs($recipient)
            ->getJson('/api/users/follow-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requester.id', $humanRequester->id);

        $this->getJson('/api/users/follow-requests/count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->get('/me')->assertSee('requestCount":1', false);

        $this->postJson("/api/users/follow-requests/{$personaRequest->id}/accept")
            ->assertNotFound();
        $this->assertSame(FollowRequest::STATUS_PENDING, $personaRequest->refresh()->status);
    }

    public function test_persona_follow_is_auto_accepted_audited_and_listed_by_edge_identity(): void
    {
        Notification::fake();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->audience(Audience::Everyone)->create([
            'display_name' => 'Kira',
        ]);

        $this->actingAs($viewer)
            ->postJson("/api/characters/{$persona->id}/follow")
            ->assertCreated()
            ->assertJsonPath('data.status', FollowRequest::STATUS_ACCEPTED)
            ->assertJsonPath('data.target.type', 'character')
            ->assertJsonPath('data.target.ulid', $persona->ulid)
            ->assertJsonPath('data.target.display_name', 'Kira');

        $follow = FollowRequest::query()
            ->where('requester_id', $viewer->id)
            ->where('recipient_id', $owner->id)
            ->where('recipient_character_id', $persona->id)
            ->firstOrFail();

        $this->assertSame(FollowRequest::STATUS_ACCEPTED, $follow->status);
        $this->assertNotNull($follow->responded_at);
        $this->assertDatabaseHas('follow_request_audit_logs', [
            'follow_request_id' => $follow->id,
            'actor_id' => $viewer->id,
            'action' => 'followed',
        ]);
        $this->assertSame(
            ['recipient_character_id' => $persona->id],
            $follow->auditLogs()->sole()->metadata,
        );
        Notification::assertNothingSent();

        $this->getJson("/api/characters/{$persona->id}/followers")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.viewer_is_following', true)
            ->assertJsonPath('data.followers.0.follower.id', $viewer->id)
            ->assertJsonPath('data.followers.0.target.type', 'character')
            ->assertJsonPath('data.followers.0.target.ulid', $persona->ulid)
            ->assertJsonMissingPath('data.followers.0.target.owner_id');
    }

    public function test_persona_follow_rejects_self_hidden_and_inactive_owner_targets(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $publicPersona = Character::factory()->for($owner)->audience(Audience::Everyone)->create();
        $hiddenPersona = Character::factory()->for($owner)->audience(Audience::SpecificPeople)->create();
        $ownPersona = Character::factory()->for($viewer)->audience(Audience::Everyone)->create();

        $this->actingAs($viewer)
            ->postJson("/api/characters/{$ownPersona->id}/follow")
            ->assertUnprocessable();

        $this->postJson("/api/characters/{$hiddenPersona->id}/follow")
            ->assertNotFound();

        $owner->forceFill(['deactivated_at' => now()])->save();
        $this->postJson("/api/characters/{$publicPersona->id}/follow")
            ->assertNotFound();

        $this->assertDatabaseCount('follow_requests', 0);
    }

    public function test_persona_follow_is_unique_per_viewer_and_can_coexist_with_human_follow(): void
    {
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->audience(Audience::Everyone)->create();

        $this->actingAs($viewer)
            ->postJson("/api/characters/{$persona->id}/follow")
            ->assertCreated();
        $this->postJson("/api/characters/{$persona->id}/follow")
            ->assertUnprocessable();
        $this->postJson("/api/users/{$owner->id}/follow-requests")
            ->assertOk();

        $this->assertDatabaseCount('follow_requests', 2);
    }

    public function test_persona_follower_list_preserves_edge_identity_and_linked_subsumption(): void
    {
        $owner = User::factory()->approved()->create(['display_name' => 'Owner']);
        $accountFollower = User::factory()->approved()->create();
        $personaFollower = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create([
            'display_name' => 'Linked Persona',
            'is_linked' => true,
        ]);
        $separate = Character::factory()->for($owner)->create([
            'display_name' => 'Separate Persona',
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
            'recipient_character_id' => $separate->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/characters/{$linked->id}/followers")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.followers.0.follower.id', $accountFollower->id)
            ->assertJsonPath('data.followers.0.target.type', 'user')
            ->assertJsonPath('data.followers.0.target.id', $owner->id);

        $this->getJson("/api/characters/{$separate->id}/followers")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.followers.0.follower.id', $personaFollower->id)
            ->assertJsonPath('data.followers.0.target.type', 'character')
            ->assertJsonPath('data.followers.0.target.ulid', $separate->ulid)
            ->assertJsonMissingPath('data.followers.0.target.owner_id');
    }
}

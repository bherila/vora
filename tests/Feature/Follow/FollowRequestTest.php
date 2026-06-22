<?php

namespace Tests\Feature\Follow;

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

        $this->actingAs($recipient)->get('/feed')->assertSee('requestCount":1', false);

        $requester->forceFill(['is_disabled' => true])->save();
        $this->actingAs($recipient)->get('/feed')->assertSee('requestCount":0', false);
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
}

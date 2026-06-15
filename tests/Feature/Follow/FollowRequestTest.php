<?php

namespace Tests\Feature\Follow;

use App\Models\FollowRequest;
use App\Models\FollowRequestAuditLog;
use App\Models\Interest;
use App\Models\InterestRating;
use App\Models\User;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_users_can_request_accept_and_follow_back_with_audit_logs(): void
    {
        Notification::fake();
        $requester = User::factory()->approved()->create(['email_follow_request_accepted' => true]);
        $recipient = User::factory()->approved()->create(['email_follow_request_received' => true]);

        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $followRequest = FollowRequest::query()->where('requester_id', $requester->id)->where('recipient_id', $recipient->id)->firstOrFail();
        Notification::assertSentTo($recipient, FollowRequestReceived::class);
        $this->assertDatabaseHas('follow_request_audit_logs', ['follow_request_id' => $followRequest->id, 'action' => 'requested']);

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        Notification::assertSentTo($requester, FollowRequestAccepted::class);
        $this->assertDatabaseHas('follow_request_audit_logs', ['follow_request_id' => $followRequest->id, 'action' => 'accepted']);
        $this->actingAs($recipient)->getJson("/api/users/{$requester->id}")
            ->assertOk()
            ->assertJsonPath('data.can_follow_back', true);
    }

    public function test_declined_requests_are_rate_limited_for_24_hours_without_email(): void
    {
        Notification::fake();
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create(['requester_id' => $requester->id, 'recipient_id' => $recipient->id, 'status' => 'pending']);

        $this->actingAs($recipient)->postJson("/api/users/follow-requests/{$followRequest->id}/decline")->assertOk();
        Notification::assertNothingSent();

        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertStatus(429);

        $followRequest->forceFill(['responded_at' => now()->subDay()->subMinute()])->save();
        $this->actingAs($requester)->postJson("/api/users/{$recipient->id}/follow-requests")
            ->assertOk();

        $this->assertSame(3, FollowRequestAuditLog::query()->count());
    }
}

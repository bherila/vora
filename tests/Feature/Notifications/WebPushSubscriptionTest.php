<?php

namespace Tests\Feature\Notifications;

use App\Models\FollowRequest;
use App\Models\User;
use App\Notifications\FollowRequestReceived;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_manage_push_subscriptions(): void
    {
        $user = User::factory()->approved()->create();
        $endpoint = 'https://push.example.test/subscription/one';

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'public-key',
                'auth' => 'auth-token',
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_type' => $user->getMorphClass(),
            'subscribable_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ]);

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'updated-public-key',
                'auth' => 'updated-auth-token',
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $endpoint,
            'public_key' => 'updated-public-key',
            'auth_token' => 'updated-auth-token',
        ]);

        $this->actingAs($user)->deleteJson('/api/push-subscriptions', ['endpoint' => $endpoint])
            ->assertOk()
            ->assertJsonPath('data.subscription_count', 0);

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $endpoint]);
    }

    public function test_push_subscription_endpoints_require_authentication(): void
    {
        $this->getJson('/api/push-subscriptions')->assertUnauthorized();
        $this->postJson('/api/push-subscriptions', [])->assertUnauthorized();
        $this->deleteJson('/api/push-subscriptions', [])->assertUnauthorized();
    }

    public function test_push_status_returns_vapid_public_key_and_subscription_count(): void
    {
        config(['webpush.vapid.public_key' => 'test-public-key']);
        $user = User::factory()->approved()->create();
        $user->updatePushSubscription('https://push.example.test/subscription/one', 'key', 'token', 'aes128gcm');

        $this->actingAs($user)->getJson('/api/push-subscriptions')
            ->assertOk()
            ->assertJsonPath('data.public_key', 'test-public-key')
            ->assertJsonPath('data.subscription_count', 1);
    }

    public function test_account_purge_removes_push_subscriptions(): void
    {
        $user = User::factory()->approved()->create();
        $user->updatePushSubscription('https://push.example.test/subscription/one', 'key', 'token', 'aes128gcm');

        app(UserAccountService::class)->purge($user);

        $this->assertDatabaseMissing('push_subscriptions', [
            'subscribable_type' => $user->getMorphClass(),
            'subscribable_id' => $user->id,
        ]);
    }

    public function test_notification_preference_suppresses_database_and_webpush_channels(): void
    {
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create(['notify_follow_request' => false]);
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => FollowRequest::STATUS_PENDING,
        ]);

        $this->assertSame([], (new FollowRequestReceived($followRequest))->via($recipient));
    }
}

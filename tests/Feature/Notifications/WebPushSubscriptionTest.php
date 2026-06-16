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

    // Realistic base64url-encoded Web Push key material (length-correct fixtures).
    // p256dh: 65-byte uncompressed EC public key → 87 base64url chars.
    // auth:   16-byte random authentication secret → 22 base64url chars.
    // Realistic base64url-encoded Web Push key material (lengths match real browser keys).
    // p256dh: 65-byte uncompressed EC public key → 87 base64url chars (no padding).
    // auth:   16-byte random secret → 22 base64url chars (no padding).
    private const P256DH = 'GafKC9wSs_tUFwv7WsqL4qtXFnvMctZBtLNilm19NeOtzjdYVOS8Jqlo-6u5wCt51FdQFQpfZwuMVaMYyAC9gxI';

    private const P256DH_2 = 'fTWKW_KH5DRpV8YHmM7Uqcav5TAxEK09v8HiagjEIe8rfbUY-gkEi0s-lqtfCzAHZHFEBOCfIJJxJpqAU82Ak8w';

    private const AUTH = 'ctQRfgA9ZwZwsFOWWCZang';

    private const AUTH_2 = 'TdN1Hw3e__g3A4-_aXslqA';

    public function test_authenticated_user_can_manage_push_subscriptions(): void
    {
        $user = User::factory()->approved()->create();
        $endpoint = 'https://push.example.test/subscription/one';

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => self::P256DH,
                'auth' => self::AUTH,
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_type' => $user->getMorphClass(),
            'subscribable_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => self::P256DH,
            'auth_token' => self::AUTH,
        ]);

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => self::P256DH_2,
                'auth' => self::AUTH_2,
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $endpoint,
            'public_key' => self::P256DH_2,
            'auth_token' => self::AUTH_2,
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

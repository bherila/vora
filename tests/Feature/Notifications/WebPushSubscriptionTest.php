<?php

namespace Tests\Feature\Notifications;

use App\Models\FollowRequest;
use App\Models\User;
use App\Notifications\FollowRequestReceived;
use App\Services\UserAccountService;
use App\Support\WebPushKeyMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    // Realistic base64url-encoded Web Push key material.
    // p256dh: 65-byte uncompressed EC public key -> 87 base64url chars.
    // auth:   16-byte random secret -> 22 base64url chars.
    private const P256DH = 'BBERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERERE';

    private const P256DH_2 = 'BCIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiI';

    private const AUTH = 'MzMzMzMzMzMzMzMzMzMzMw';

    private const AUTH_2 = 'RERERERERERERERERERERA';

    public function test_authenticated_user_can_manage_push_subscriptions(): void
    {
        $user = User::factory()->approved()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/one';

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => self::P256DH,
                'auth' => self::AUTH,
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $row = DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();
        $this->assertNotNull($row);
        $this->assertSame($user->getMorphClass(), $row->subscribable_type);
        $this->assertSame($user->id, $row->subscribable_id);
        $this->assertSame(WebPushKeyMaterial::base64UrlToBinary(self::P256DH), $row->public_key_bytes);
        $this->assertSame(WebPushKeyMaterial::base64UrlToBinary(self::AUTH), $row->auth_token_bytes);
        $this->assertFalse(Schema::hasColumn('push_subscriptions', 'public_key'));
        $this->assertFalse(Schema::hasColumn('push_subscriptions', 'auth_token'));

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => self::P256DH_2,
                'auth' => self::AUTH_2,
            ],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()
            ->assertJsonPath('data.subscription_count', 1);

        $row = DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();
        $this->assertSame(WebPushKeyMaterial::base64UrlToBinary(self::P256DH_2), $row->public_key_bytes);
        $this->assertSame(WebPushKeyMaterial::base64UrlToBinary(self::AUTH_2), $row->auth_token_bytes);
        $this->assertSame(self::P256DH_2, $user->pushSubscriptions()->firstOrFail()->public_key);
        $this->assertSame(self::AUTH_2, $user->pushSubscriptions()->firstOrFail()->auth_token);

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

    public function test_push_status_returns_vapid_public_key_count_and_endpoint_ownership(): void
    {
        config(['webpush.vapid.public_key' => 'test-public-key']);
        $user = User::factory()->approved()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/one';
        $user->updatePushSubscription($endpoint, self::P256DH, self::AUTH, 'aes128gcm');

        $this->actingAs($user)->getJson('/api/push-subscriptions?endpoint='.urlencode($endpoint))
            ->assertOk()
            ->assertJsonPath('data.public_key', 'test-public-key')
            ->assertJsonPath('data.subscription_count', 1)
            ->assertJsonPath('data.endpoint_registered', true);

        $this->actingAs($user)->getJson('/api/push-subscriptions?endpoint='.urlencode('https://updates.push.services.mozilla.com/wpush/v2/other'))
            ->assertOk()
            ->assertJsonPath('data.subscription_count', 1)
            ->assertJsonPath('data.endpoint_registered', false);
    }

    public function test_invalid_push_subscription_payloads_are_rejected(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'http://1.1.1.1/subscription/one',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('endpoint');

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://127.0.0.1/subscription/one',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('endpoint');

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/one',
            'keys' => ['p256dh' => 'not-a-real-key', 'auth' => self::AUTH],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('keys.p256dh');

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/one',
            'keys' => ['p256dh' => self::P256DH, 'auth' => 'bad'],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('keys.auth');
    }

    public function test_only_known_push_services_are_accepted(): void
    {
        $user = User::factory()->approved()->create();
        $store = fn (string $endpoint) => $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ]);

        foreach ([
            'https://1.1.1.1/subscription/one',
            'https://push.example.com/subscription/one',
            'https://notify.windows.com.example.com/w/?token=one',
            'https://evilnotify.windows.com/w/?token=one',
            'https://fcm.googleapis.com:8443/fcm/send/one',
        ] as $endpoint) {
            $store($endpoint)->assertStatus(422)->assertJsonValidationErrorFor('endpoint');
        }

        foreach ([
            'https://fcm.googleapis.com/fcm/send/two',
            'https://updates.push.services.mozilla.com/wpush/v2/three',
            'https://web.push.apple.com/QGuQyavXutnMH0',
            'https://wns2-by3p.notify.windows.com/w/?token=four',
        ] as $endpoint) {
            $store($endpoint)->assertOk();
        }
    }

    public function test_each_account_keeps_a_bounded_number_of_subscriptions(): void
    {
        config(['webpush.max_subscriptions_per_user' => 3]);
        $user = User::factory()->approved()->create();
        foreach (range(1, 3) as $device) {
            $user->updatePushSubscription("https://fcm.googleapis.com/fcm/send/device-{$device}", self::P256DH, self::AUTH, 'aes128gcm');
            $this->travel(1)->minutes();
        }

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-4',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertOk()->assertJsonPath('data.subscription_count', 3);

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1']);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-4']);
    }

    public function test_registering_subscriptions_is_rate_limited(): void
    {
        $user = User::factory()->approved()->create();

        foreach (range(1, 10) as $device) {
            $this->actingAs($user)->postJson('/api/push-subscriptions', [
                'endpoint' => "https://fcm.googleapis.com/fcm/send/device-{$device}",
                'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
            ])->assertOk();
        }

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-11',
            'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH],
        ])->assertStatus(429);
    }

    public function test_overlong_key_payloads_are_rejected_before_decoding(): void
    {
        $user = User::factory()->approved()->create();

        // A request-sized base64url-looking key must be rejected on length, before
        // the validator base64-decodes the whole string.
        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/one',
            'keys' => ['p256dh' => str_repeat('A', 5000), 'auth' => self::AUTH],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('keys.p256dh');

        $this->actingAs($user)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/one',
            'keys' => ['p256dh' => self::P256DH, 'auth' => str_repeat('A', 5000)],
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('keys.auth');
    }

    public function test_account_purge_removes_push_subscriptions(): void
    {
        $user = User::factory()->approved()->create();
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/one', self::P256DH, self::AUTH, 'aes128gcm');

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

    public function test_web_push_uses_queue_while_database_channel_remains_inline(): void
    {
        config(['queue.default' => 'database']);
        $requester = User::factory()->approved()->create();
        $recipient = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => FollowRequest::STATUS_PENDING,
        ]);
        $notification = new FollowRequestReceived($followRequest);

        $this->assertSame('sync', $notification->viaConnections()['database']);
        $this->assertSame('database', $notification->viaConnections()[WebPushChannel::class]);
    }
}

<?php

namespace Tests\Feature\Waitlist;

use App\Mail\WaitlistInviteMail;
use App\Mail\WaitlistVerificationMail;
use App\Models\Invite;
use App\Models\User;
use App\Models\WaitlistRequest;
use App\Services\SettingsService;
use App\Services\WaitlistService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaitlistRequestTest extends TestCase
{
    use RefreshDatabase;

    private function closeSignups(): void
    {
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'hopeful@example.com',
            'birth_date' => today()->subYears(25)->toDateString(),
            'interests' => 'I love building communities and writing collaborative fiction with friends.',
        ], $overrides);
    }

    #[Test]
    public function test_request_is_created_and_verification_email_sent_when_signups_closed(): void
    {
        Mail::fake();
        $this->closeSignups();

        $this->postJson('/api/waitlist', $this->payload())->assertOk();

        $this->assertDatabaseHas('waitlist_requests', [
            'email' => 'hopeful@example.com',
            'verified_at' => null,
        ]);
        Mail::assertSent(WaitlistVerificationMail::class);
    }

    #[Test]
    public function test_request_is_blocked_when_public_signups_open(): void
    {
        Mail::fake();
        // Default settings leave public signups open.

        $this->postJson('/api/waitlist', $this->payload())->assertStatus(422);

        $this->assertDatabaseMissing('waitlist_requests', ['email' => 'hopeful@example.com']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function test_underage_request_is_rejected(): void
    {
        $this->closeSignups();

        $this->postJson('/api/waitlist', $this->payload([
            'birth_date' => today()->subYears(18)->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('birth_date');

        $this->assertDatabaseMissing('waitlist_requests', ['email' => 'hopeful@example.com']);
    }

    #[Test]
    public function test_short_interests_are_rejected(): void
    {
        $this->closeSignups();

        $this->postJson('/api/waitlist', $this->payload(['interests' => 'too short']))
            ->assertStatus(422)->assertJsonValidationErrors('interests');
    }

    #[Test]
    public function test_cloudflare_ip_and_geo_are_captured(): void
    {
        Mail::fake();
        $this->closeSignups();

        $this->withHeaders([
            'CF-Connecting-IP' => '203.0.113.7',
            'CF-IPCountry' => 'CA',
            'CF-IPCity' => 'Toronto',
        ])->postJson('/api/waitlist', $this->payload())->assertOk();

        $request = WaitlistRequest::firstWhere('email', 'hopeful@example.com');
        $this->assertSame('203.0.113.7', $request->ip_address);
        $this->assertSame('CA', $request->geo['country']);
        $this->assertSame('Toronto', $request->geo['city']);
    }

    #[Test]
    public function test_email_is_verified_by_code(): void
    {
        Mail::fake();
        $this->closeSignups();
        $this->postJson('/api/waitlist', $this->payload())->assertOk();

        $code = null;
        Mail::assertSent(WaitlistVerificationMail::class, function (WaitlistVerificationMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $uuid = WaitlistRequest::firstWhere('email', 'hopeful@example.com')->uuid;

        $this->postJson('/api/waitlist/verify', ['uuid' => $uuid, 'code' => $code])->assertOk();

        $this->assertNotNull(WaitlistRequest::firstWhere('email', 'hopeful@example.com')->verified_at);
    }

    #[Test]
    public function test_email_is_verified_by_link(): void
    {
        Mail::fake();
        $this->closeSignups();
        $this->postJson('/api/waitlist', $this->payload())->assertOk();

        $token = null;
        Mail::assertSent(WaitlistVerificationMail::class, function (WaitlistVerificationMail $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $uuid = WaitlistRequest::firstWhere('email', 'hopeful@example.com')->uuid;

        $this->get("/waitlist/verify/{$uuid}/{$token}")->assertOk();

        $this->assertNotNull(WaitlistRequest::firstWhere('email', 'hopeful@example.com')->verified_at);
    }

    #[Test]
    public function test_wrong_code_does_not_verify(): void
    {
        Mail::fake();
        $this->closeSignups();
        $this->postJson('/api/waitlist', $this->payload())->assertOk();

        $uuid = WaitlistRequest::firstWhere('email', 'hopeful@example.com')->uuid;

        $this->postJson('/api/waitlist/verify', ['uuid' => $uuid, 'code' => 'definitely-not-the-code'])
            ->assertStatus(422);

        $this->assertNull(WaitlistRequest::firstWhere('email', 'hopeful@example.com')->verified_at);
    }

    #[Test]
    public function test_non_admin_cannot_view_waitlist(): void
    {
        User::factory()->admin()->create(); // occupy id 1 (always-admin)
        $user = User::factory()->approved()->create(); // a genuine non-admin

        $this->actingAs($user)->getJson('/api/admin/waitlist')->assertForbidden();
    }

    #[Test]
    public function test_admin_can_admit_a_verified_request(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $request = WaitlistRequest::factory()->verified()->create(['email' => 'join@example.com']);

        $this->actingAs($admin)
            ->postJson("/api/admin/waitlist/{$request->uuid}/admit")
            ->assertOk();

        $request->refresh();
        $this->assertNotNull($request->admitted_at);
        $this->assertNotNull($request->invite_id);

        $invite = Invite::find($request->invite_id);
        $this->assertTrue($invite->auto_approve);
        $this->assertSame('join@example.com', $invite->email);
        Mail::assertSent(WaitlistInviteMail::class);
    }

    #[Test]
    public function test_admin_cannot_admit_an_unverified_request(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $request = WaitlistRequest::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/waitlist/{$request->uuid}/admit")
            ->assertStatus(422);

        $this->assertNull($request->refresh()->admitted_at);
        Mail::assertNothingSent();
    }

    #[Test]
    public function test_admin_can_delete_a_request(): void
    {
        $admin = User::factory()->admin()->create();
        $request = WaitlistRequest::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/waitlist/{$request->uuid}")
            ->assertOk();

        $this->assertDatabaseMissing('waitlist_requests', ['id' => $request->id]);
    }

    #[Test]
    public function test_admitted_invite_creates_auto_approved_preverified_account(): void
    {
        Notification::fake();
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $request = WaitlistRequest::factory()->verified()->create(['email' => 'join@example.com']);
        $invite = app(WaitlistService::class)->admit($request, $admin);

        $this->closeSignups();

        $this->postJson('/api/auth/register', [
            'name' => 'New Member',
            'display_name' => 'newbie',
            'birth_date' => today()->subYears(30)->toDateString(),
            // Tampered email in the body must be ignored in favour of the bound one.
            'email' => 'attacker@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
            'invite' => $invite->uuid,
        ])->assertOk();

        $user = User::firstWhere('email', 'join@example.com');
        $this->assertNotNull($user);
        $this->assertNull(User::firstWhere('email', 'attacker@example.com'));
        $this->assertNotNull($user->approved_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($invite->id, $user->referred_by_invite_id);
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }
}

<?php

namespace Tests\Feature\Invites;

use App\Models\Invite;
use App\Models\InviteGrant;
use App\Models\User;
use App\Services\InviteService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InviteSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Invited User',
            'display_name' => 'Invitee',
            'birth_date' => today()->subYears(21)->toDateString(),
            'email' => 'invited@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $overrides);
    }

    private function closeSignups(): void
    {
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
    }

    private function generatedInviteFrom(User $inviter): Invite
    {
        InviteGrant::factory()->create(['user_id' => $inviter->id, 'quantity' => 3, 'remaining' => 3]);

        return app(InviteService::class)->generate($inviter->refresh());
    }

    #[Test]
    public function test_registration_blocked_when_signups_closed_and_no_invite(): void
    {
        User::factory()->admin()->create();
        $this->closeSignups();

        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertStatus(422);
        $this->assertNull(User::firstWhere('email', 'invited@example.com'));
    }

    #[Test]
    public function test_registration_allowed_with_valid_invite_and_links_referrer(): void
    {
        $inviter = User::factory()->approved()->create();
        $this->closeSignups();
        $invite = $this->generatedInviteFrom($inviter);

        $response = $this->postJson('/api/auth/register', $this->payload(['invite' => $invite->uuid]));

        $response->assertOk();
        $newUser = User::firstWhere('email', 'invited@example.com');
        $this->assertNotNull($newUser);
        $this->assertSame($invite->id, $newUser->referred_by_invite_id);

        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame($newUser->id, $invite->invited_user_id);
        // Referrer reachable through the invite — forms the tree.
        $this->assertSame($inviter->id, $newUser->referredByInvite->inviter_user_id);
    }

    #[Test]
    public function test_invite_is_single_use(): void
    {
        $inviter = User::factory()->approved()->create();
        $this->closeSignups();
        $invite = $this->generatedInviteFrom($inviter);

        $this->postJson('/api/auth/register', $this->payload(['invite' => $invite->uuid]))->assertOk();

        // Registration logs the first invitee in; sign back out so the second
        // request is a guest (guest middleware would otherwise 302 an auth'd user).
        auth()->logout();
        $this->flushSession();

        $second = $this->postJson('/api/auth/register', $this->payload([
            'email' => 'second@example.com',
            'invite' => $invite->uuid,
        ]));

        $second->assertStatus(422);
        $this->assertNull(User::firstWhere('email', 'second@example.com'));
    }

    #[Test]
    public function test_expired_invite_is_rejected(): void
    {
        $inviter = User::factory()->approved()->create();
        $this->closeSignups();
        $invite = Invite::factory()->expired()->create(['inviter_user_id' => $inviter->id]);

        $this->postJson('/api/auth/register', $this->payload(['invite' => $invite->uuid]))
            ->assertStatus(422);
    }

    #[Test]
    public function test_inviteless_signup_when_open_has_null_referrer(): void
    {
        User::factory()->admin()->create();

        $this->postJson('/api/auth/register', $this->payload())->assertOk();

        $user = User::firstWhere('email', 'invited@example.com');
        $this->assertNotNull($user);
        $this->assertNull($user->referred_by_invite_id);
    }

    #[Test]
    public function test_trusted_inviter_invitee_is_auto_approved(): void
    {
        $inviter = User::factory()->trustedInviter()->create();
        $this->closeSignups();
        $invite = $this->generatedInviteFrom($inviter);

        $this->postJson('/api/auth/register', $this->payload(['invite' => $invite->uuid]))->assertOk();

        $user = User::firstWhere('email', 'invited@example.com');
        $this->assertTrue($user->isApproved());
        $this->assertSame($inviter->id, $user->approved_by_user_id);
    }

    #[Test]
    public function test_untrusted_inviter_invitee_stays_pending(): void
    {
        $inviter = User::factory()->approved()->create();
        $this->closeSignups();
        $invite = $this->generatedInviteFrom($inviter);

        $this->postJson('/api/auth/register', $this->payload(['invite' => $invite->uuid]))->assertOk();

        $this->assertFalse(User::firstWhere('email', 'invited@example.com')->isApproved());
    }

    #[Test]
    public function test_generated_invite_is_valid_for_at_least_72_hours(): void
    {
        $inviter = User::factory()->approved()->create();
        // Grant expires in 1 hour — the generated link must still last >= 72h.
        InviteGrant::factory()->expiresInDays(1)->create([
            'user_id' => $inviter->id,
            'expires_at' => now()->addHour(),
        ]);

        $invite = app(InviteService::class)->generate($inviter->refresh());

        $this->assertNotNull($invite->expires_at);
        $this->assertTrue($invite->expires_at->greaterThan(now()->addHours(71)));
    }

    #[Test]
    public function test_generate_fails_without_balance(): void
    {
        $inviter = User::factory()->approved()->create();

        $this->expectException(\RuntimeException::class);
        app(InviteService::class)->generate($inviter);
    }

    #[Test]
    public function test_issue_to_all_skips_users_barred_from_invites(): void
    {
        $admin = User::factory()->admin()->create();
        $eligible = User::factory()->approved()->create();
        $barred = User::factory()->approved()->cannotReceiveInvites()->create();

        $count = app(InviteService::class)->issueToAll(2, null, $admin);

        $this->assertGreaterThanOrEqual(2, $count); // admin + eligible (+ barred excluded)
        $this->assertSame(2, app(InviteService::class)->availableBalance($eligible));
        $this->assertSame(0, app(InviteService::class)->availableBalance($barred));
    }

    #[Test]
    public function test_invite_from_banned_inviter_is_not_usable(): void
    {
        $inviter = User::factory()->approved()->create();
        $invite = $this->generatedInviteFrom($inviter);

        $inviter->forceFill(['banned_at' => now()])->save();

        $this->assertNull(app(InviteService::class)->findUsable($invite->uuid));
    }
}

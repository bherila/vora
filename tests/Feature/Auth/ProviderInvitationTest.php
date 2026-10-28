<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\InviteGrant;
use App\Models\ProviderInvitation;
use App\Models\User;
use App\Services\Auth\ProviderAccounts;
use App\Services\Auth\ProviderInvitationService;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\OAuth\Invitations\ApplicationInvitations;
use BWH\Auth\OAuth\OAuthIdentity;
use BWH\Auth\Testing\FakeApplicationInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProviderInvitationTest extends TestCase
{
    use RefreshDatabase;

    private FakeApplicationInvitations $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => 'https://id.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
        ]);
        $this->provider = FakeApplicationInvitations::swap();
        // Account 1 is always an administrator; keep it out of the way.
        User::factory()->create(['id' => 1]);
    }

    private function signedIn(User $user): static
    {
        return $this->actingAs($user)->withSession([RequireProviderAccount::SESSION_KEY => [$user->oauth_provider, $user->oauth_subject]]);
    }

    private function bound(string $subject, bool $admin = false): User
    {
        $factory = User::factory()->approved();

        return ($admin ? $factory->admin() : $factory)->create(['oauth_provider' => 'example', 'oauth_subject' => $subject]);
    }

    public function test_an_administrator_invites_by_email_and_sees_the_link_once(): void
    {
        $admin = $this->bound('7', admin: true);

        $response = $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.invitation.email', 'guest@example.test')
            ->assertJsonPath('data.invitation.status', 'pending')
            ->assertJsonPath('data.invitation.email_sent', true);
        $this->assertStringStartsWith(FakeApplicationInvitations::LINK_BASE, $response->json('data.link'));
        $this->provider->assertCreated('guest@example.test', actor: '7');

        // The link is a bearer credential: never stored, never listed again.
        $this->signedIn($admin)->getJson('/api/invitations')
            ->assertOk()
            ->assertJsonPath('data.balance', null)
            ->assertJsonPath('data.invitations.0.invited_by', $admin->display_name)
            ->assertDontSee(FakeApplicationInvitations::LINK_BASE);
        $this->assertDatabaseHas('provider_invitations', [
            'oauth_provider' => 'example', 'provider_invitation_id' => 1, 'inviter_user_id' => $admin->id, 'invite_grant_id' => null,
        ]);
    }

    public function test_others_spend_an_invite_from_their_balance_and_only_once_the_provider_accepts(): void
    {
        $guest = $this->bound('8');
        $this->signedIn($guest)->postJson('/api/invitations', ['email' => 'friend@example.test'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You have no invites available. An administrator can issue you more.');
        $this->signedIn($guest)->get('/feed')->assertDontSee('/invitations');

        $grant = InviteGrant::create(['user_id' => $guest->id, 'quantity' => 1, 'remaining' => 1]);
        $this->signedIn($guest)->get('/feed')->assertSee('/invitations');

        // A refusal spends nothing.
        $this->provider->refuseNext('create', ApplicationInvitationError::RateLimited, retryAfter: 60, limit: 'actor');
        $this->signedIn($guest)->postJson('/api/invitations', ['email' => 'friend@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60');
        $this->assertSame(1, $grant->fresh()->remaining);
        $this->assertDatabaseCount('provider_invitations', 0);

        $this->signedIn($guest)->postJson('/api/invitations', ['email' => 'friend@example.test'])->assertCreated();
        $this->assertSame(0, $grant->fresh()->remaining);
        $this->assertDatabaseHas('provider_invitations', ['inviter_user_id' => $guest->id, 'invite_grant_id' => $grant->id]);
        $this->signedIn($guest)->getJson('/api/invitations')
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.invitations.0.invited_by', null);
        $this->signedIn($guest)->postJson('/api/invitations', ['email' => 'another@example.test'])->assertForbidden();
        // The page stays reachable to follow, resend or revoke what was sent.
        $this->signedIn($guest)->get('/feed')->assertSee('/invitations');
    }

    public function test_a_new_invitation_replaces_the_pending_one_and_a_first_sign_in_marks_it_joined(): void
    {
        $admin = $this->bound('7', admin: true);
        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'Guest@Example.test'])->assertCreated();
        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])->assertCreated();

        $this->assertSame(
            [ProviderInvitation::SUPERSEDED, ProviderInvitation::PENDING],
            ProviderInvitation::orderBy('id')->pluck('status')->all(),
        );

        app(ProviderAccounts::class)->resolve(new OAuthIdentity('example', '99', 'Guest', 'guest@example.test'));
        $this->assertSame(
            [ProviderInvitation::SUPERSEDED, ProviderInvitation::ACCEPTED],
            ProviderInvitation::orderBy('id')->pluck('status')->all(),
        );
    }

    public function test_the_sender_or_an_administrator_resends_and_revokes_pending_invitations(): void
    {
        $sender = $this->bound('8');
        $other = $this->bound('9');
        $admin = $this->bound('7', admin: true);
        InviteGrant::create(['user_id' => $sender->id, 'quantity' => 2, 'remaining' => 2]);
        $id = $this->signedIn($sender)->postJson('/api/invitations', ['email' => 'friend@example.test'])->json('data.invitation.id');

        // Someone else's invitation is not theirs to see or touch.
        $this->signedIn($other)->postJson("/api/invitations/{$id}/resend")->assertNotFound();
        $this->signedIn($other)->postJson("/api/invitations/{$id}/revoke")->assertNotFound();
        $this->signedIn($other)->getJson('/api/invitations')->assertJsonCount(0, 'data.invitations');

        $this->travel(8)->days();
        $this->signedIn($sender)->getJson('/api/invitations')->assertJsonPath('data.invitations.0.status', 'expired');
        $resent = $this->signedIn($sender)->postJson("/api/invitations/{$id}/resend")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.invitation.status', 'pending');
        $this->assertStringStartsWith(FakeApplicationInvitations::LINK_BASE, $resent->json('data.link'));
        $this->provider->assertResent(1, actor: '8');

        $this->signedIn($admin)->postJson("/api/invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.invitation.status', 'revoked');
        $this->provider->assertRevoked(1, actor: '7');
        $this->signedIn($sender)->postJson("/api/invitations/{$id}/resend")->assertStatus(409);
    }

    public function test_an_accepted_invitation_cannot_be_revoked_and_is_shown_as_joined(): void
    {
        $admin = $this->bound('7', admin: true);
        $id = $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])->json('data.invitation.id');
        $this->provider->accept(1);

        $this->signedIn($admin)->postJson("/api/invitations/{$id}/revoke")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This invitation was already accepted.');
        $this->assertSame(ProviderInvitation::ACCEPTED, ProviderInvitation::sole()->status);
    }

    public function test_provider_refusals_are_explained_without_the_address(): void
    {
        $admin = $this->bound('7', admin: true);
        Log::spy();

        $this->provider->refuseNext('create', ApplicationInvitationError::InvalidRequest, errors: ['email' => ['The email must be a valid email address.']]);
        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])
            ->assertStatus(422)->assertJsonPath('message', 'The email must be a valid email address.');

        $this->provider->refuseNext('create', ApplicationInvitationError::ApplicationNotEnabled);
        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])
            ->assertStatus(503)->assertJsonPath('message', 'Invitations are not available right now. Ask an administrator to check the sign-in provider settings.');
        Log::shouldHaveReceived('warning')->once()->with('Provider invitation request failed.', ['error' => 'application_not_enabled', 'status' => 403]);

        $this->provider->refuseNext('create', ApplicationInvitationError::ActorNotEligible);
        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])->assertForbidden();

        $this->signedIn($admin)->postJson('/api/invitations', ['email' => 'not an address'])->assertUnprocessable();
        $this->assertDatabaseCount('provider_invitations', 0);
    }

    public function test_a_balance_spent_meanwhile_is_refused_and_other_failures_are_not_reported_as_one(): void
    {
        $guest = $this->bound('8');
        // Spent between the page's check and the request: nothing is sent.
        try {
            app(ProviderInvitationService::class)->create($guest, 'friend@example.test');
            $this->fail('A spent balance must be refused.');
        } catch (HttpException $refused) {
            $this->assertSame([403, ProviderInvitationService::NO_INVITES], [$refused->getStatusCode(), $refused->getMessage()]);
        }
        $this->provider->assertNothingRequested();

        InviteGrant::create(['user_id' => $guest->id, 'quantity' => 1, 'remaining' => 1]);
        $this->mock(ApplicationInvitations::class)->shouldReceive('create')->andThrow(new PDOException('SQLSTATE[HY000] example-detail'));
        $this->withoutExceptionHandling();
        $this->expectException(PDOException::class);
        $this->signedIn($guest)->postJson('/api/invitations', ['email' => 'friend@example.test']);
    }

    public function test_invitations_are_served_only_under_provider_sign_in(): void
    {
        $admin = $this->bound('7', admin: true);
        config(['auth.sign_in' => 'local']);

        $this->actingAs($admin)->get('/invitations')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'guest@example.test'])->assertNotFound();
        $this->provider->assertNothingRequested();
    }
}

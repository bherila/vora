<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProviderSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://id.example.test';

    /** @var array<string, mixed> */
    protected array $identity = ['sub' => '42', 'name' => 'Avery Example', 'email' => 'avery@example.test', 'credential_version' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.sign_in' => 'provider',
            'bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.base_url' => self::PROVIDER_URL,
            'bherila-auth.oauth_client.client_id' => 'client-1',
            'bherila-auth.oauth_client.client_secret' => 'secret-1',
            'bherila-auth.oauth_client.redirect_uri' => 'http://localhost/oauth/callback',
        ]);
        Http::fake([
            self::PROVIDER_URL.'/oauth/token' => fn () => Http::response(['access_token' => 'access-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::PROVIDER_URL.'/api/oauth/user' => fn () => Http::response($this->identity),
        ]);
    }

    /** Runs the provider round trip and returns the callback response. */
    private function signIn(): TestResponse
    {
        $location = (string) $this->get('/login')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::PROVIDER_URL.'/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);

        return $this->get('/oauth/callback?'.http_build_query(['code' => 'code-1', 'state' => $query['state']]));
    }

    public function test_first_sign_in_creates_a_bound_approved_account_that_records_its_birth_date_once(): void
    {
        // Administrators are exempt from the age gate, so the first lands home.
        $this->signIn()->assertRedirect('/');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['example', '42', 'avery@example.test', 'Avery Example'], [$user->oauth_provider, $user->oauth_subject, $user->email, $user->display_name]);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->isApproved());
        // The first account administers the installation, as with local sign-up.
        $this->assertTrue($user->is_admin);

        // A later account is not an administrator, and still has no birth date.
        $this->post('/logout');
        $this->identity = ['sub' => '43', 'name' => 'Blake Example', 'email' => 'blake@example.test'];
        $this->signIn()->assertRedirect('/age-eligibility');
        $guest = User::where('oauth_subject', '43')->sole();
        $this->assertFalse($guest->is_admin);
        $this->get('/feed')->assertRedirect('/age-eligibility');
        $this->get('/age-eligibility')->assertOk()->assertSee('Enter your birth date.')->assertSee('at least 18 years old');

        $this->post('/age-eligibility/birth-date', ['birth_date' => today()->subYears(10)->toDateString()])
            ->assertSessionHasErrors(['birth_date' => 'You must be at least 18 years old to hold an account on this site.']);
        $this->post('/age-eligibility/birth-date', ['birth_date' => today()->subYears(30)->toDateString()])
            ->assertRedirect('/age-eligibility');
        $this->assertSame(today()->subYears(30)->toDateString(), $guest->fresh()->birth_date->toDateString());
        $this->get('/feed')->assertOk();
        // Corrections after that stay with administrators.
        $this->post('/age-eligibility/birth-date', ['birth_date' => today()->subYears(40)->toDateString()])->assertForbidden();
    }

    public function test_a_site_that_only_verifies_ages_still_asks_for_the_birth_date(): void
    {
        app(SettingsService::class)->set(SettingsService::MINIMUM_AGE, '');
        app(SettingsService::class)->set(SettingsService::AGE_VERIFICATION_REQUIRED, '1');
        User::factory()->admin()->create();

        $this->signIn()->assertRedirect('/age-eligibility');
        $this->get('/age-eligibility')->assertOk()->assertSee('Enter your birth date.')
            ->assertSee('An administrator must confirm your age');
        $this->post('/age-eligibility/birth-date', ['birth_date' => '1990-05-01'])->assertRedirect('/age-eligibility');
        $this->assertSame('1990-05-01', User::where('oauth_subject', '42')->sole()->birth_date->toDateString());
    }

    public function test_an_existing_account_is_reached_only_once_an_operator_links_it(): void
    {
        $user = User::factory()->approved()->create(['email' => 'avery@example.test', 'name' => 'Avery Local', 'display_name' => 'Avery', 'force_change_pw' => true]);

        // Never linked by email: the address belongs to an unbound account.
        $this->signIn()->assertStatus(409);
        $this->assertGuest();

        $this->artisan('ops:bind-provider-account', ['email' => 'avery@example.test', 'subject' => '42'])->assertSuccessful();
        // A local forced password change has nothing to change here any more.
        $this->signIn()->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // The provider owns the address; the names stay the person's own.
        $this->post('/logout');
        $this->identity['email'] = 'avery.new@example.test';
        $this->identity['name'] = 'Avery Renamed';
        $this->signIn()->assertRedirect();
        $user->refresh();
        $this->assertSame(['avery.new@example.test', 'Avery Local', 'Avery'], [$user->email, $user->name, $user->display_name]);
        $this->assertSame(1, User::count());

        // A new address still held by another row, here a deleted account, is
        // not adopted, and does not lock the bound account out.
        $this->post('/logout');
        User::factory()->create(['email' => 'taken@example.test'])->delete();
        $this->identity['email'] = 'taken@example.test';
        $this->signIn()->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('avery.new@example.test', $user->fresh()->email);
    }

    public function test_the_bind_command_never_moves_an_existing_link(): void
    {
        User::factory()->create(['email' => 'a@example.test', 'oauth_provider' => 'example', 'oauth_subject' => '42']);
        User::factory()->create(['email' => 'b@example.test']);

        $this->artisan('ops:bind-provider-account', ['email' => 'missing@example.test', 'subject' => '7'])->assertFailed();
        $this->artisan('ops:bind-provider-account', ['email' => 'a@example.test', 'subject' => '7'])->assertFailed();
        $this->artisan('ops:bind-provider-account', ['email' => 'b@example.test', 'subject' => '42'])->assertFailed();
        $this->artisan('ops:bind-provider-account', ['email' => 'a@example.test', 'subject' => '42'])->assertSuccessful();
        $this->assertNull(User::where('email', 'b@example.test')->sole()->oauth_subject);
    }

    public function test_subjects_differing_only_in_case_are_different_people(): void
    {
        User::factory()->create(['email' => 'first@example.test', 'oauth_provider' => 'example', 'oauth_subject' => 'AbC']);
        $this->identity = ['sub' => 'abc', 'name' => 'Second Person', 'email' => 'second@example.test'];

        $this->signIn()->assertRedirect();

        $this->assertSame('second@example.test', auth()->user()->email);
        $this->assertSame(2, User::count());
    }

    public function test_a_disabled_account_is_not_signed_in(): void
    {
        User::factory()->create(['email' => 'avery@example.test', 'oauth_provider' => 'example', 'oauth_subject' => '42', 'is_disabled' => true]);

        $this->signIn()->assertForbidden();
        $this->assertGuest();
    }

    public function test_local_credential_pages_are_not_served_and_unbound_sessions_end(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('Sign up')->assertSee('Log in');
        foreach (['/register', '/forgot-password', '/request-invitation', '/i/abc'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'secret'])->assertNotFound();

        // A session from before the switch was not made through the provider.
        $local = User::factory()->create();
        $this->actingAs($local)->getJson('/api/account/storage')->assertUnauthorized();
        $this->assertGuest();
        $this->actingAs($local)->get('/user/settings')->assertRedirect('/login');

        // Linking an account does not vouch for the password session it had.
        $bound = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => '42']);
        $this->actingAs($bound)->get('/user/settings')->assertRedirect('/login');
        $this->assertGuest();

        $this->actingAs($bound)->withSession([RequireProviderAccount::SESSION_KEY => ['example', '42']]);
        $this->get('/user/invites')->assertNotFound();
        $this->get('/privacy')->assertDontSee('Invites');
        $settings = (string) $this->get('/user/settings')->assertOk()->getContent();
        $this->assertStringContainsString('"provider_account_url":"'.self::PROVIDER_URL.'/settings"', str_replace('\\/', '/', $settings));
        $this->patchJson('/api/account', ['name' => $bound->name, 'email' => 'other@example.test'])
            ->assertForbidden()->assertJsonPath('message', 'Your email is managed by your sign-in provider.');

        // Ended before any account gate answers for it, even one it would fail.
        $ineligible = User::factory()->approved()->create(['birth_date' => null]);
        $this->actingAs($ineligible)->get('/feed')->assertRedirect('/login');
        $this->assertGuest();

        // An account bound to another provider is not this provider's either.
        $elsewhere = User::factory()->create(['oauth_provider' => 'other', 'oauth_subject' => '42']);
        $this->actingAs($elsewhere)->get('/user/settings')->assertRedirect('/login');
    }

    public function test_admins_issue_invites_for_provider_invitations_but_admit_nobody_from_a_waitlist(): void
    {
        $admin = User::factory()->admin()->create(['oauth_provider' => 'example', 'oauth_subject' => '1']);
        $member = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => '2']);

        $this->actingAs($admin)->withSession([RequireProviderAccount::SESSION_KEY => ['example', '1']]);
        // A balance pays for invitations the provider emails (ProviderInvitationTest).
        $this->postJson("/api/admin/users/{$member->id}/invites", ['quantity' => 2])->assertOk();
        $this->assertSame(2, $member->inviteGrants()->sum('remaining'));
        $this->postJson('/api/admin/waitlist/1/admit')->assertNotFound();
        $this->get('/privacy')->assertDontSee('Invitation requests');
        // The page itself stays: it also holds the age requirements.
        $this->getJson('/api/admin/invites')->assertOk();
    }

    public function test_sign_out_ends_the_provider_session_too(): void
    {
        $this->signIn();
        $this->assertAuthenticated();

        $response = $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        $this->assertStringStartsWith(self::PROVIDER_URL.'/oauth/end-session?', (string) $response->json('redirect'));
    }

    public function test_the_provider_callback_is_not_served_under_local_sign_in(): void
    {
        config(['auth.sign_in' => 'local']);

        $this->get('/oauth/callback?code=x&state=y')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_enforcement_ends_a_session_the_provider_no_longer_vouches_for(): void
    {
        config(['bherila-auth.provider_identity.enabled' => true]);
        $active = true;
        Http::fake([
            self::PROVIDER_URL.'/api/reconciliation/identity-status' => function () use (&$active) {
                return Http::response($active
                    ? ['contract_version' => 1, 'active' => true, 'subject' => '42', 'credential_version' => 1]
                    : ['contract_version' => 1, 'active' => false]);
            },
        ]);
        $this->signIn()->assertRedirect();
        $this->assertAuthenticated();
        $statusChecks = fn (): int => Http::recorded(fn ($request) => str_ends_with($request->url(), '/identity-status'))->count();
        $afterSignIn = $statusChecks();

        // Uploads send many POSTs; they share the last answer instead of each
        // spending the provider's per-client status throttle...
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/push-subscriptions', []);
        }
        $this->assertSame($afterSignIn, $statusChecks());
        // ...while an administrator's change is checked afresh.
        $this->putJson('/api/admin/app-icon/short-name', ['short_name' => 'Example'])->assertOk();
        $this->assertSame($afterSignIn + 1, $statusChecks());

        $active = false;
        // Within the freshness window ordinary requests share the last answer...
        $this->getJson('/api/account/storage')->assertOk();
        // ...after it, the provider is asked again and the session ends.
        $this->travel(301)->seconds();
        $this->getJson('/api/account/storage')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_local_credential_endpoints_are_off_in_the_package_config(): void
    {
        $load = function (string $mode): array {
            putenv("AUTH_SIGN_IN={$mode}");
            try {
                return (require config_path('bherila-auth.php'))['routes'];
            } finally {
                putenv('AUTH_SIGN_IN');
            }
        };

        $provider = $load('provider');
        $this->assertSame([false, false, false, false], [$provider['passkeys'], $provider['password_resets'], $provider['change_password'], $provider['two_factor']]);
        $this->assertTrue($load('local')['passkeys']);
    }
}

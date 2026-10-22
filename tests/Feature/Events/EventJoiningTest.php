<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Invite;
use App\Models\User;
use App\Services\Auth\VoraAuthUserPolicy;
use App\Services\SettingsService;
use BWH\Auth\Models\TwoFactorAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventJoiningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true]);
        Notification::fake();
        User::factory()->admin()->create(['id' => 100]);
    }

    private function event(string $visibility = 'everyone'): Event
    {
        return Event::create(['name' => 'Hidden gathering name', 'description' => 'Private location', 'visibility' => $visibility, 'timezone' => 'UTC']);
    }

    private function joinUrl(Event $event): string
    {
        return '/events/join/'.$event->ulid;
    }

    public function test_guest_joining_link_keeps_destination_when_switching_login_and_registration_without_metadata(): void
    {
        $event = $this->event('specific');
        $join = $this->joinUrl($event);
        $this->get($join.'?next=https://evil.example')->assertRedirect(route('login'))
            ->assertSessionHas('events.join_ulid', $event->ulid);
        foreach (['/login', '/register', '/login'] as $path) {
            $this->get($path)->assertOk()->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === $join)
                ->assertDontSee($event->name)->assertDontSee($event->description);
        }
        $this->assertDatabaseCount('event_user', 0);
    }

    public function test_password_and_two_factor_keep_joining_destination_until_event_authorization(): void
    {
        $event = $this->event('specific');
        $user = User::factory()->approved()->create();
        $event->members()->attach($user);
        $join = $this->joinUrl($event);
        $this->get($join)->assertRedirect(route('login'));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('requires_2fa', true);
        $this->assertGuest();
        $attempt = TwoFactorAttempt::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->get('/login/two-factor/'.$attempt->token)->assertOk()
            ->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === $join);
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => 'invalid'])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/api/auth/two-factor/verify', ['attempt_token' => $attempt->token, 'code' => $attempt->code])
            ->assertOk()->assertJsonPath('redirect', $join);
        $this->assertAuthenticatedAs($user);
        $this->get($join)->assertRedirect(route('events.join.continue'));
        $this->get('/events/continue')->assertRedirect(route('events.index', ['event_ulid' => $event->ulid]))
            ->assertSessionMissing('events.join_ulid')->assertSessionMissing('url.intended');
    }

    public function test_personal_signup_invitation_email_verification_and_pending_approval_resume_the_original_event(): void
    {
        $event = $this->event();
        $join = $this->joinUrl($event);
        $this->get($join);
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
        $inviter = User::factory()->approved()->create();
        $invite = Invite::factory()->create(['inviter_user_id' => $inviter->id, 'auto_approve' => false]);
        $this->get('/i/'.$invite->uuid)->assertRedirect(route('register', ['invite' => $invite->uuid]));
        $this->get('/register?invite='.$invite->uuid)->assertOk()
            ->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === $join && $data['register']['invite_valid']);
        $redirect = $this->postJson('/api/auth/register', [
            'name' => 'New attendee', 'display_name' => 'Attendee', 'email' => 'attendee@example.test',
            'birth_date' => '1990-01-01', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'invite' => $invite->uuid,
        ])->assertOk()->json('redirect');
        $user = User::where('email', 'attendee@example.test')->firstOrFail();
        $this->assertFalse($user->isAdmin());
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->approved_at);
        $this->get($redirect)->assertOk()->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === $join);
        $this->get('/events/continue')->assertRedirect(route('verification.notice'));
        $verification = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($verification)->assertRedirect(route('approval.pending'));
        $this->get('/pending-approval')->assertOk()->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === $join);
        $this->get('/events/continue')->assertRedirect(route('approval.pending', ['source' => 'login']));
        $this->getJson('/api/events')->assertForbidden();
        $user->forceFill(['approved_at' => now()])->save();
        $this->actingAs($user->fresh())->get('/pending-approval')->assertRedirect($join);
        $this->get($join)->assertRedirect(route('events.join.continue'));
        $this->get('/events/continue')->assertRedirect(route('events.index', ['event_ulid' => $event->ulid]));
        $this->assertDatabaseCount('event_user', 0);
    }

    public function test_event_link_does_not_bypass_closed_signups_without_a_personal_invitation(): void
    {
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
        $event = $this->event();
        $this->get($this->joinUrl($event))->assertRedirect(route('login'));
        $this->get('/register')->assertOk()->assertViewHas('initialData', fn (array $data): bool => ! $data['register']['public_signups_enabled'] && ! $data['register']['invite_valid']);
        $this->postJson('/api/auth/register', [
            'name' => 'Uninvited attendee', 'display_name' => 'Attendee', 'email' => 'uninvited@example.test',
            'birth_date' => '1990-01-01', 'password' => 'password-123', 'password_confirmation' => 'password-123',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'uninvited@example.test']);
        $this->assertDatabaseCount('event_user', 0);
    }

    public function test_joining_never_grants_restricted_membership_and_hidden_or_missing_links_are_indistinguishable(): void
    {
        $event = $this->event('specific');
        $missing = '/events/join/'.Str::ulid();
        foreach ([$this->joinUrl($event), $missing] as $join) {
            $this->get($join)->assertRedirect(route('login'));
        }
        $user = User::factory()->approved()->create();
        $bodies = [];
        foreach ([$this->joinUrl($event), $missing] as $join) {
            $this->actingAs($user)->get($join)->assertRedirect(route('events.join.continue'));
            $response = $this->get('/events/continue')->assertNotFound()->assertViewIs('events.unavailable')
                ->assertSee('This event isn’t available to your account. Check the link or ask the organizer for access.')
                ->assertSee('href="/events"', false)->assertDontSee($event->name)->assertDontSee($event->description)
                ->assertDontSee($event->ulid)->assertDontSee(basename($missing));
            $bodies[] = $response->getContent();
        }
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertDatabaseCount('event_user', 0);
    }

    public function test_module_disable_and_invalid_joining_identifiers_fail_closed(): void
    {
        foreach (['https%3A%2F%2Fevil.example', '%2F%2Fevil.example', 'not-an-event'] as $ulid) {
            $this->get('/events/join/'.$ulid)->assertNotFound();
        }
        $event = $this->event();
        config(['instance.modules.events' => false]);
        $this->get($this->joinUrl($event))->assertNotFound()->assertSessionMissing('events.join_ulid');
        $this->withSession(['events.join_ulid' => 'https://evil.example'])->get('/login')->assertOk()
            ->assertViewHas('initialData', fn (array $data): bool => $data['eventJoin']['continue_url'] === null)
            ->assertSessionMissing('events.join_ulid');
    }

    public function test_joining_preserves_required_password_change_and_disabled_account_gate(): void
    {
        $event = $this->event();
        $user = User::factory()->approved()->create(['force_change_pw' => true]);
        $this->actingAs($user)->get($this->joinUrl($event))->assertRedirect(route('events.join.continue'));
        $this->get('/events/continue')->assertRedirect(route('user.settings'))->assertSessionHas('events.join_ulid', $event->ulid);
        $user->forceFill(['is_disabled' => true])->save();
        $this->actingAs($user->fresh())->get('/events/continue')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_email_verification_preserves_password_change_before_event_return(): void
    {
        $event = $this->event();
        $user = User::factory()->approved()->unverified()->create(['force_change_pw' => true]);
        $this->actingAs($user)->get($this->joinUrl($event));
        $verification = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($verification)->assertRedirect(route('user.settings'))->assertSessionHas('events.join_ulid', $event->ulid);
    }

    public function test_auth_redirects_reject_external_and_encoded_redirects_but_preserve_safe_local_destinations(): void
    {
        $user = User::factory()->approved()->create();
        $request = Request::create('http://localhost/api/auth/two-factor/verify', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $policy = app(VoraAuthUserPolicy::class);
        foreach (['https://evil.example', '//evil.example', '///evil.example', '/\\evil.example', '/%5cevil.example', '/%2f%2fevil.example',
            'javascript:alert(1)', 'http://localhost@evil.example', 'http://localhost:8000/events', 'https://localhost/events', 'events', ['invalid']] as $bad) {
            $request->session()->put('url.intended', $bad);
            $this->assertSame('/', $policy->redirectAfterLogin($user, $request));
            $this->assertFalse($request->session()->has('url.intended'));
        }
        foreach (['/events?featured=1', 'http://localhost/events?featured=1'] as $safe) {
            $request->session()->put('url.intended', $safe);
            $this->assertSame('/events?featured=1', $policy->redirectAfterLogin($user, $request));
        }
    }
}

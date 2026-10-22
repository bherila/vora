<?php

namespace Tests\Feature\Auth;

use App\Models\Event;
use App\Models\Media;
use App\Models\StaticPage;
use App\Models\User;
use App\Services\Auth\AgeEligibility;
use App\Services\Auth\VoraAuthUserPolicy;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AgeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->admin = User::factory()->admin()->create(['id' => 100]);
    }

    private function minimum(?int $age): void
    {
        app(SettingsService::class)->set(SettingsService::MINIMUM_AGE, $age ?? '');
    }

    private function payload(string $date): array
    {
        return ['name' => 'Attendee', 'display_name' => 'Guest', 'birth_date' => $date, 'email' => 'guest@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'];
    }

    public function test_defaults_and_settings_are_admin_only_and_validate_bounds(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/invites')->assertOk()
            ->assertJsonPath('data.settings.minimum_age', 18)->assertJsonPath('data.settings.age_verification_required', false);
        foreach ([-1, 0, 121, 13.5, 'invalid'] as $invalid) {
            $this->putJson('/api/admin/invites/settings', ['minimum_age' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('minimum_age');
        }
        $this->putJson('/api/admin/invites/settings', ['age_verification_required' => 'yes'])->assertUnprocessable();
        $this->putJson('/api/admin/invites/settings', ['minimum_age' => 13, 'age_verification_required' => true])->assertOk()
            ->assertJsonPath('data.minimum_age', 13)->assertJsonPath('data.age_verification_required', true);
        $this->putJson('/api/admin/invites/settings', ['minimum_age' => null])->assertOk()->assertJsonPath('data.minimum_age', null);
        $user = User::factory()->approved()->create(['id_verified_at' => now()]);
        $this->actingAs($user)->putJson('/api/admin/invites/settings', ['minimum_age' => 1])->assertForbidden();
        $this->assertNull(app(SettingsService::class)->minimumAge());
        auth()->logout();
        $this->putJson('/api/admin/invites/settings', ['minimum_age' => 1])->assertUnauthorized();
    }

    public function test_registration_and_waitlist_share_configurable_birthday_boundary_and_keep_admission_gates(): void
    {
        $this->minimum(13);
        $this->postJson('/api/auth/register', $this->payload(today()->subYears(13)->addDay()->toDateString()))->assertUnprocessable()->assertJsonValidationErrors('birth_date');
        $this->postJson('/api/auth/register', $this->payload(today()->subYears(13)->toDateString()) + ['minimum_age' => 0, 'id_verified' => true, 'approved_at' => now()])->assertOk();
        $user = User::where('email', 'guest@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->approved_at);
        $this->assertNull($user->id_verified_at);
        $this->assertFalse($user->isAdmin());
        auth()->logout();
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
        $waitlist = ['email' => 'waiting@example.test', 'interests' => 'I would like to share wedding memories with my family.'];
        $this->postJson('/api/waitlist', $waitlist + ['birth_date' => today()->subYears(13)->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/waitlist', $waitlist + ['birth_date' => today()->subYears(13)->toDateString()])->assertOk();
    }

    public function test_no_minimum_accepts_young_accounts_but_rejects_future_dates(): void
    {
        $this->minimum(null);
        $this->postJson('/api/auth/register', $this->payload(today()->addDay()->toDateString()))->assertUnprocessable();
        $this->postJson('/api/auth/register', $this->payload(today()->subYears(5)->toDateString()))->assertOk();
        auth()->logout();
        app(SettingsService::class)->set(SettingsService::PUBLIC_SIGNUPS_ENABLED, false);
        $this->postJson('/api/waitlist', ['email' => 'young@example.test', 'birth_date' => today()->subYears(5)->toDateString(), 'interests' => 'I am requesting an invitation from the wedding organizer.'])->assertOk();
    }

    public function test_live_policy_gates_existing_sessions_media_and_saves_and_releases_without_relogin(): void
    {
        $user = User::factory()->approved()->create(['birth_date' => today()->subYears(15)->toDateString()]);
        $media = Media::factory()->for($this->admin)->approved()->create();
        $this->minimum(13);
        $this->actingAs($user)->get('/feed')->assertOk();
        $this->minimum(18);
        $this->get('/feed')->assertRedirect('/age-eligibility');
        $this->get('/age-eligibility')->assertOk()->assertSee('at least 18 years old');
        $this->getJson('/api/events/media')->assertForbidden()->assertJsonPath('code', 'age_eligibility_required');
        $event = Event::create(['name' => 'Hidden until eligible', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $hidden = $this->postJson('/api/events/'.$event->ulid.'/uploads', [])->assertForbidden()->assertJsonPath('code', 'age_eligibility_required')->json();
        $missing = $this->postJson('/api/events/01ARZ3NDEKTSV4RRFFQ69G5FAV/uploads', [])->assertForbidden()->json();
        $this->assertSame($hidden, $missing);
        $this->getJson('/api/media/'.$media->id.'/hls/master.m3u8')->assertForbidden()->assertJsonPath('code', 'age_eligibility_required');
        $this->postJson('/api/library/save', ['media_ulid' => $media->ulid])->assertForbidden()->assertJsonPath('code', 'age_eligibility_required');
        $this->get('/user/settings')->assertOk();
        $this->get('/terms')->assertOk();
        $this->getJson('/api/account/storage')->assertOk();
        $this->getJson('/api/passkeys')->assertOk();
        $this->postJson('/api/change-password', ['current_password' => 'password', 'password' => 'updated-password123', 'password_confirmation' => 'updated-password123'])->assertOk();
        $this->patchJson('/api/account', ['display_name' => 'Updated'])->assertOk();
        $this->assertSame('minimum_age', app(AgeEligibility::class)->reason($user));
        $this->minimum(null);
        $this->get('/feed')->assertOk();
    }

    public function test_manual_verification_is_separate_from_approval_and_admin_correction_revokes_it(): void
    {
        $settings = app(SettingsService::class);
        $user = User::factory()->approved()->create(['birth_date' => '1990-01-01']);
        $this->actingAs($user)->get('/feed')->assertOk();
        $settings->set(SettingsService::AGE_VERIFICATION_REQUIRED, true);
        $this->get('/feed')->assertRedirect('/age-eligibility');
        $this->get('/age-eligibility')->assertOk()->assertSee('administrator must confirm your age');
        $this->patchJson('/api/account', ['display_name' => 'Same guest', 'id_verified' => true, 'id_verified_at' => now(), 'minimum_age' => null])->assertOk();
        $this->assertNull($user->fresh()->id_verified_at);
        $this->get('/feed')->assertRedirect('/age-eligibility');
        $this->actingAs($this->admin)->patchJson('/api/admin/users/'.$user->id, ['id_verified' => true])->assertOk();
        $this->actingAs($user->fresh())->get('/feed')->assertOk();
        $this->actingAs($this->admin)->patchJson('/api/admin/users/'.$user->id, ['birth_date' => '1991-01-01', 'id_verified' => true])->assertOk()->assertJsonPath('data.id_verified', false);
        $this->actingAs($user->fresh())->get('/feed')->assertRedirect('/age-eligibility');
        $settings->set(SettingsService::AGE_VERIFICATION_REQUIRED, false);
        $this->get('/feed')->assertOk();
    }

    public function test_accurate_underage_correction_cannot_be_bypassed_by_manual_verification(): void
    {
        $user = User::factory()->approved()->create();
        $this->actingAs($this->admin)->patchJson('/api/admin/users/'.$user->id, ['birth_date' => today()->subYears(12)->toDateString()])->assertOk();
        $this->patchJson('/api/admin/users/'.$user->id, ['id_verified' => true])->assertOk();
        $this->actingAs($user->fresh())->get('/feed')->assertRedirect('/age-eligibility');
        $this->actingAs($this->admin)->get('/admin/invites')->assertOk();
        $this->putJson('/api/admin/invites/settings', ['minimum_age' => 120, 'age_verification_required' => true])->assertOk();
        $this->get('/admin/users')->assertOk();
    }

    public function test_email_approval_and_login_destination_precede_age_gate_and_keep_joining_destination(): void
    {
        $settings = app(SettingsService::class);
        $settings->set(SettingsService::AGE_VERIFICATION_REQUIRED, true);
        $user = User::factory()->unverified()->create(['approved_at' => null]);
        $this->actingAs($user)->get('/feed')->assertRedirect('/email/verify');
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->get('/feed')->assertRedirect('/pending-approval?source=login');
        $user->forceFill(['approved_at' => now()])->save();
        $request = Request::create('/login');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('url.intended', '/events');
        $this->assertSame('/age-eligibility', app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
        $this->assertSame('/events', $request->session()->get('url.intended'));
        config(['instance.modules.events' => true]);
        $joining = '/events/join/01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->actingAs($user)->get($joining)->assertRedirect('/events/continue');
        $this->get('/events/continue')->assertRedirect('/age-eligibility')->assertSessionHas('events.join_ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $settings->set(SettingsService::AGE_VERIFICATION_REQUIRED, false);
        $this->get('/age-eligibility')->assertRedirect($joining);
    }

    public function test_ban_and_deactivation_gate_pages_remain_reachable_without_redirect_loops(): void
    {
        app(SettingsService::class)->set(SettingsService::AGE_VERIFICATION_REQUIRED, true);
        $user = User::factory()->approved()->create(['banned_at' => now()]);
        $this->actingAs($user)->get('/feed')->assertRedirect('/account/banned');
        $this->get('/account/banned')->assertOk();
        $user->forceFill(['banned_at' => null, 'deactivated_at' => now()])->save();
        $this->actingAs($user)->get('/feed')->assertRedirect('/account/deactivated');
        $this->get('/account/deactivated')->assertOk();
    }

    public function test_default_and_legacy_seeded_terms_follow_current_policy_and_copy_does_not_promise_disabled_verification(): void
    {
        $this->minimum(13);
        $this->get('/terms')->assertOk()->assertSee('at least 13 years old')->assertDontSee('at least 18 years old');
        $this->get('/register')->assertOk()->assertSee('"minimum_age":13', false)->assertSee('"age_verification_required":false', false);
        StaticPage::create(['slug' => 'terms', 'title' => 'Terms', 'body_markdown' => 'You must be at least 18 years old or the age of majority in your local jurisdiction, whichever is higher, and legally able to enter into these terms.', 'is_published' => true]);
        $this->minimum(null);
        app(SettingsService::class)->set(SettingsService::AGE_VERIFICATION_REQUIRED, true);
        $this->get('/terms')->assertOk()->assertSee('no configured minimum account age')->assertSee('administrator must confirm your age')->assertDontSee('at least 18');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Mail\ConfirmEmailChangeMail;
use App\Mail\EmailChangeRequestedMail;
use App\Models\User;
use App\Services\Account\EmailChangeService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->approved()->create([
            'email' => 'current@example.com',
            'email_verified_at' => now()->subDay(),
        ]);
    }

    private function confirmationUrl(): string
    {
        $url = null;
        Mail::assertSent(ConfirmEmailChangeMail::class, function (ConfirmEmailChangeMail $mail) use (&$url): bool {
            $url = $mail->confirmUrl;

            return true;
        });
        $this->assertIsString($url);

        return $url;
    }

    #[Test]
    public function an_email_change_requires_the_current_password(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame('current@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    #[Test]
    public function saving_other_fields_with_the_unchanged_email_needs_no_password(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)->patchJson('/api/account', ['name' => 'Renamed', 'email' => 'current@example.com'])
            ->assertOk();

        $this->assertSame('Renamed', $user->fresh()->name);
    }

    #[Test]
    public function the_new_address_takes_effect_only_after_its_link_is_opened(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new@example.com', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'current@example.com')
            ->assertJsonPath('data.pending_email', 'new@example.com');
        Mail::assertSent(EmailChangeRequestedMail::class, fn (EmailChangeRequestedMail $mail): bool => $mail->hasTo('current@example.com') && $mail->newEmail === 'new@example.com');
        $this->assertSame('current@example.com', $user->fresh()->email);

        $this->actingAs($user)->get($this->confirmationUrl())
            ->assertRedirect(route('user.settings', ['email_change' => 'confirmed']));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
    }

    #[Test]
    public function the_link_only_works_for_the_same_account(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();
        $other = User::factory()->approved()->create();

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new@example.com', 'current_password' => 'password'])->assertOk();
        $url = $this->confirmationUrl();

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertSame('current@example.com', $user->fresh()->email);
    }

    #[Test]
    public function a_tampered_expired_or_superseded_link_changes_nothing(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'first@example.com', 'current_password' => 'password'])->assertOk();
        $first = $this->confirmationUrl();
        $this->actingAs($user)->get($first.'x')->assertForbidden();

        // A newer request replaces the pending address; the older link is spent.
        $user->forceFill(['pending_email' => 'second@example.com'])->save();
        $this->actingAs($user)->get($first)
            ->assertRedirect(route('user.settings', ['email_change' => 'failed']));
        $this->assertSame('current@example.com', $user->fresh()->email);

        $expired = app(EmailChangeService::class)->confirmationUrl($user, 'second@example.com');
        $this->travel(61)->minutes();
        $this->actingAs($user)->get($expired)->assertForbidden();
        $this->assertSame('current@example.com', $user->fresh()->email);
    }

    #[Test]
    public function an_address_taken_before_confirmation_is_not_applied(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new@example.com', 'current_password' => 'password'])->assertOk();
        $url = $this->confirmationUrl();
        User::factory()->create(['email' => 'new@example.com']);

        $this->actingAs($user)->get($url)
            ->assertRedirect(route('user.settings', ['email_change' => 'failed']));
        $this->assertSame('current@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email);
    }

    #[Test]
    public function an_unverified_address_is_replaced_directly_and_re_verified(): void
    {
        Mail::fake();
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'typo@exmaple.com']);

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'fixed@example.com', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'fixed@example.com')
            ->assertJsonPath('data.pending_email', null);

        $this->assertSame('fixed@example.com', $user->fresh()->email);
        Notification::assertSentTo($user, VerifyEmail::class);
        Mail::assertNotSent(EmailChangeRequestedMail::class);
    }

    #[Test]
    public function email_changes_are_rate_limited_without_limiting_other_saves(): void
    {
        Mail::fake();
        $user = $this->verifiedUser();

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->patchJson('/api/account', ['email' => "new{$i}@example.com", 'current_password' => 'password'])->assertOk();
        }

        $this->actingAs($user)->patchJson('/api/account', ['email' => 'new7@example.com', 'current_password' => 'password'])
            ->assertStatus(429);
        $this->actingAs($user)->patchJson('/api/account', ['name' => 'Still saves', 'email' => 'current@example.com'])
            ->assertOk();
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\MailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(?User $user, string $recipient, string $status, string $type = 'VerifyEmail', int $minutesAgo = 1): MailDelivery
    {
        return MailDelivery::create([
            'mailer' => 'hybrid', 'provider_message_id' => uniqid('msg-'), 'recipient' => $recipient, 'user_id' => $user?->id,
            'type' => $type, 'status' => $status, 'sent_at' => now()->subMinutes($minutesAgo), 'status_at' => now(),
        ]);
    }

    public function test_reports_the_latest_verification_email_for_this_account_and_address_only(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'guest@exmaple.test']);
        $this->actingAs($user)->getJson('/email/verification-status')->assertOk()
            ->assertJsonPath('data.email', 'guest@exmaple.test')->assertJsonPath('data.verified', false)->assertJsonPath('data.delivery', null);

        // Another account's report for the same address, and other mail types, never count.
        $this->delivery(User::factory()->create(), 'guest@exmaple.test', 'delivered');
        $this->delivery($user, 'guest@exmaple.test', 'delivered', 'ResetPassword');
        $this->delivery($user, 'guest@exmaple.test', 'delivered', minutesAgo: 10);
        $this->delivery($user, 'guest@exmaple.test', 'bounced', minutesAgo: 2);
        $this->getJson('/email/verification-status')->assertOk()->assertJsonPath('data.delivery.status', 'bounced');

        // After changing address, the old bounce no longer applies.
        $user->update(['email' => 'guest@example.test']);
        $this->getJson('/email/verification-status')->assertOk()->assertJsonPath('data.email', 'guest@example.test')->assertJsonPath('data.delivery', null);
    }

    public function test_requires_sign_in_and_exposes_the_email_lock(): void
    {
        $this->getJson('/email/verification-status')->assertUnauthorized();
        $locked = User::factory()->unverified()->create(['email_locked' => true]);
        $this->actingAs($locked)->getJson('/email/verification-status')->assertOk()->assertJsonPath('data.email_locked', true);
    }
}

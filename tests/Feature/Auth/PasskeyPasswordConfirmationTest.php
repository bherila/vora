<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Http\Middleware\RequirePasswordConfirmation;
use BWH\Auth\Models\PasskeyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasskeyPasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_passkey_enrollment_routes_require_fresh_confirmation(): void
    {
        $user = User::factory()->approved()->create();

        foreach (['/api/passkeys/register/options', '/api/passkeys/register'] as $uri) {
            $this->actingAs($user)->postJson($uri)
                ->assertStatus(423)
                ->assertJsonPath('error', 'password_confirmation_required');
        }
    }

    #[Test]
    public function a_session_must_confirm_its_password_before_requesting_enrollment_options(): void
    {
        $user = User::factory()->approved()->create();

        // No Accept header, as the package's passkey client sends.
        $this->actingAs($user)->post('/api/passkeys/register/options')
            ->assertStatus(423)
            ->assertJsonPath('error', 'password_confirmation_required');

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])
            ->assertOk()->assertJsonPath('success', true);
        $this->actingAs($user)->postJson('/api/passkeys/register/options')
            ->assertOk()->assertJsonStructure(['challenge']);
    }

    #[Test]
    public function an_existing_owned_user_verified_passkey_confirms_without_a_password_or_account_switch(): void
    {
        $user = User::factory()->approved()->create();
        [$id, $key] = $this->credential($user);
        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);
        $this->assertion($id, $key, false)->assertUnprocessable();
        $this->postJson('/api/passkeys/register/options')->assertStatus(423);
        $this->assertion($id, $key)->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($user);
        $this->assertSame((string) $user->id, session(RequirePasswordConfirmation::PASSKEY_SESSION_KEY.'.account'));
        $this->postJson('/api/passkeys/register/options')->assertOk();
    }

    #[Test]
    public function another_accounts_signed_passkey_cannot_confirm_the_current_account(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        [$id, $key] = $this->credential($other);
        $this->actingAs($user);
        $this->assertion($id, $key)->assertUnprocessable();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/passkeys/register/options')->assertStatus(423);
    }

    #[Test]
    public function a_confirmation_expires_with_the_password_timeout(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])->assertOk();
        $this->travel((int) config('auth.password_timeout') + 1)->seconds();

        $this->actingAs($user)->postJson('/api/passkeys/register/options')->assertStatus(423);
    }

    #[Test]
    public function password_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->approved()->create();

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'wrong'])->assertStatus(422);
        }

        $this->actingAs($user)->postJson('/api/auth/confirm-password', ['password' => 'password'])->assertStatus(429);
    }

    /** @return array{string, OpenSSLAsymmetricKey} */
    private function credential(User $user): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $public = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)."\x22\x58\x20".str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $id = $this->base64Url(random_bytes(32));
        PasskeyCredential::create([
            'user_id' => $user->id, 'credential_id' => $id, 'public_key' => base64_encode($public),
            'counter' => 0, 'rp_id' => 'id.example.test', 'name' => 'Synthetic existing passkey',
        ]);

        return [$id, $key];
    }

    private function assertion(string $id, OpenSSLAsymmetricKey $key, bool $userVerified = true): TestResponse
    {
        $options = $this->postJson('https://id.example.test/api/passkeys/confirm/options')->assertOk()->assertJsonPath('userVerification', 'required');
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options->json('challenge'), 'origin' => 'https://id.example.test'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authenticator = hash('sha256', 'id.example.test', true).($userVerified ? "\x05" : "\x01").pack('N', (int) PasskeyCredential::where('credential_id', $id)->value('counter') + 1);
        $this->assertTrue(openssl_sign($authenticator.hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256));

        $response = $this->postJson('https://id.example.test/api/passkeys/confirm', ['credential' => [
            'id' => $id, 'rawId' => $id, 'type' => 'public-key',
            'response' => ['clientDataJSON' => $this->base64Url($client), 'authenticatorData' => $this->base64Url($authenticator), 'signature' => $this->base64Url($signature), 'userHandle' => $this->base64Url((string) auth()->id())],
        ]]);

        return $response;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

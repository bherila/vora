<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\LocalIdentityMigration;
use BWH\Auth\Models\PasskeyCredential;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use OpenSSLAsymmetricKey;
use Tests\Support\EmbeddedAuthTestCase;

class LocalIdentityPasskeyLoginTest extends EmbeddedAuthTestCase
{
    public function test_the_original_credential_signs_a_real_provider_assertion_after_migration_without_signing_in_to_the_host(): void
    {
        [$clientId, $secret] = app(EmbeddedContext::class)->run(function (): array {
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Vora', [self::ORIGIN.'/oauth/callback']);

            return [(string) $client->getKey(), (string) $client->plain_secret];
        });
        config([
            'bherila-auth.oauth_client.client_id' => $clientId,
            'bherila-auth.oauth_client.client_secret' => $secret,
            'auth-manager.sign_in.code_after_password' => true,
            'auth-manager.sign_in.code_only' => false,
        ]);
        // A non-default ID catches accidental reallocation of the WebAuthn
        // user handle when the provider directory starts empty.
        $source = User::factory()->approved()->create(['id' => 41]);
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        $this->assertSame(32, strlen($details['ec']['x']));
        $this->assertSame(32, strlen($details['ec']['y']));
        // COSE EC2 / ES256 / P-256 map: {1:2, 3:-7, -1:1, -2:x, -3:y}.
        $publicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$details['ec']['x']
            ."\x22\x58\x20".$details['ec']['y'];
        $credentialId = $this->base64Url(random_bytes(32));
        $credential = PasskeyCredential::create([
            'user_id' => $source->id, 'credential_id' => $credentialId,
            'public_key' => base64_encode($publicKey), 'counter' => 29,
            'rp_id' => '127.0.0.1', 'name' => 'Synthetic original device', 'transports' => ['internal'],
        ]);
        $original = (array) DB::table('auth_passkeys')->find($credential->id);

        $report = app(LocalIdentityMigration::class)->run(true, copyPasskeys: true, rpId: '127.0.0.1', origin: self::ORIGIN);
        $this->assertFalse($report['failed']);
        $this->assertSame('migrated', $report['accounts'][0]['status']);
        $this->assertSame('41', $source->fresh()->oauth_subject);

        $options = $this->freshRequest()->withCredentials()->postJson('/auth/api/passkeys/auth/options')->assertOk()
            ->assertJsonPath('rpId', '127.0.0.1');
        $session = $options->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $assertion = $this->assertion($credentialId, (string) $options->json('challenge'), $source->id, 30, $privateKey);
        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->postJson('/auth/api/passkeys/auth', ['credential' => $assertion])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('redirect', '/auth');

        $this->assertSame(41, auth('auth-manager')->id());
        $this->assertGuest('web');
        $this->assertNull($source->fresh()->last_login_at);
        $providerKey = DB::table('auth_manager_webauthn_credentials')->sole();
        $this->assertSame(41, $providerKey->user_id);
        $this->assertSame($credentialId, $providerKey->credential_id);
        $this->assertSame(base64_encode($publicKey), $providerKey->public_key);
        $this->assertSame(30, $providerKey->counter);
        $this->assertNotNull($providerKey->last_used_at);
        $this->assertSame($original, (array) DB::table('auth_passkeys')->find($credential->id));

        // A new guest ceremony with the same copied public key must still check
        // cryptography; possessing its ID and user handle is insufficient.
        $options = $this->freshRequest()->withCredentials()->postJson('/auth/api/passkeys/auth/options')->assertOk();
        $session = $options->getCookie('auth_manager_session');
        $this->assertNotNull($session);
        $forged = $this->assertion($credentialId, (string) $options->json('challenge'), $source->id, 31, $privateKey);
        $signature = base64_decode(strtr($forged['response']['signature'], '-_', '+/'));
        $signature[strlen($signature) - 1] = chr(ord($signature[strlen($signature) - 1]) ^ 1);
        $forged['response']['signature'] = $this->base64Url($signature);
        $this->freshRequest()->withCookie('auth_manager_session', (string) $session->getValue())
            ->postJson('/auth/api/passkeys/auth', ['credential' => $forged])->assertStatus(422);
        $this->assertGuest('auth-manager');
        $this->assertGuest('web');
        $this->assertSame(30, DB::table('auth_manager_webauthn_credentials')->value('counter'));
        $this->assertSame($original, (array) DB::table('auth_passkeys')->find($credential->id));
    }

    /** @return array<string, mixed> */
    private function assertion(string $credentialId, string $challenge, int $userId, int $counter, OpenSSLAsymmetricKey $privateKey): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => self::ORIGIN, 'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // UP + UV flags, followed by the authenticator's big-endian counter.
        $authenticatorData = hash('sha256', '127.0.0.1', true)."\x05".pack('N', $counter);
        $this->assertTrue(openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return [
            'id' => $credentialId, 'rawId' => $credentialId, 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64Url($clientData),
                'authenticatorData' => $this->base64Url($authenticatorData),
                'signature' => $this->base64Url($signature),
                'userHandle' => $this->base64Url((string) $userId),
            ],
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

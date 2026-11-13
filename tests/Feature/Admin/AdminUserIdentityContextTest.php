<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\RequireProviderAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserIdentityContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['bherila-auth.oauth_client.provider' => 'example',
            'bherila-auth.oauth_client.client_id' => 'synthetic-client',
            'bherila-auth.oauth_client.client_secret' => 'synthetic-secret',
            'bherila-auth.oauth_client.base_url' => 'https://identity.example.test']);
        $actor = User::factory()->admin()->create(['id' => 1, 'oauth_provider' => 'example', 'oauth_subject' => 'actor']);
        $this->actingAs($actor)->withSession([RequireProviderAccount::SESSION_KEY => ['example', 'actor']]);
    }

    /** @return array<string, array{string, string}> */
    public static function providerModes(): array
    {
        return ['external' => ['provider', 'https://identity.example.test'], 'embedded' => ['embedded', 'https://app.example.test/auth']];
    }

    #[DataProvider('providerModes')]
    public function test_admin_support_context_distinguishes_exact_bindings_without_changing_accounts(string $mode, string $base): void
    {
        config(['auth.sign_in' => $mode, 'bherila-auth.oauth_client.base_url' => $base]);
        $bound = User::factory()->approved()->create(['oauth_provider' => 'example', 'oauth_subject' => 'Opaque/Subject?x=<example>', 'email_locked' => true]);
        $local = User::factory()->approved()->create();
        $foreign = User::factory()->approved()->create(['oauth_provider' => 'other', 'oauth_subject' => 'foreign']);
        $differentCase = User::factory()->approved()->create(['oauth_provider' => 'Example', 'oauth_subject' => 'case']);
        $incomplete = User::factory()->approved()->create(['oauth_provider' => null, 'oauth_subject' => 'partial']);
        $before = $bound->fresh()->getRawOriginal();

        $response = $this->getJson('/api/admin/users')->assertOk();
        $users = collect($response->json('data'))->keyBy('id');
        $this->assertSame(['sign_in_mode' => $mode, 'configured_provider' => 'example', 'binding' => 'configured_provider',
            'provider' => 'example', 'subject' => 'Opaque/Subject?x=<example>', 'provider_account_url' => $base.'/settings',
            'local_email_lock_available' => false], $users[$bound->id]['identity']);
        $this->assertSame('local', $users[$local->id]['identity']['binding']);
        $this->assertTrue($users[$local->id]['identity']['local_email_lock_available']);
        foreach ([$foreign, $differentCase] as $user) {
            $this->assertSame('other_provider', $users[$user->id]['identity']['binding']);
            $this->assertNull($users[$user->id]['identity']['provider_account_url']);
        }
        $this->assertSame('incomplete', $users[$incomplete->id]['identity']['binding']);
        $this->assertNull($users[$incomplete->id]['identity']['provider_account_url']);
        $this->assertSame($before, $bound->fresh()->getRawOriginal());
        $response->assertDontSee('synthetic-secret')->assertDontSee('password')->assertDontSee('remember_token');
        Http::assertNothingSent();
    }

    public function test_local_mode_keeps_local_email_controls_and_does_not_offer_provider_handoffs(): void
    {
        config(['auth.sign_in' => 'local']);
        $local = User::factory()->approved()->create(['email_locked' => true]);
        $users = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('local', $users[$local->id]['identity']['sign_in_mode']);
        $this->assertTrue($users[$local->id]['identity']['local_email_lock_available']);
        $this->assertNull($users[$local->id]['identity']['configured_provider']);
        $this->assertNull($users[1]['identity']['provider_account_url']);
        $this->assertFalse($users[1]['identity']['local_email_lock_available']);
    }

    /** @return array<string, array{string, string}> */
    public static function unavailableProviderSettings(): array
    {
        return ['http' => ['http://identity.example.test', 'client'], 'script' => ['javascript:alert(1)', 'client'],
            'userinfo' => ['https://user:secret@identity.example.test', 'client'],
            'query' => ['https://identity.example.test/auth?next=elsewhere', 'client'],
            'fragment' => ['https://identity.example.test/auth#fragment', 'client'],
            'encoded traversal' => ['https://identity.example.test/%2e%2e', 'client'],
            'malformed' => ['not a URL', 'client'], 'unconfigured' => ['https://identity.example.test', '']];
    }

    #[DataProvider('unavailableProviderSettings')]
    public function test_only_valid_configured_provider_urls_are_exposed(string $base, string $client): void
    {
        config(['auth.sign_in' => 'provider', 'bherila-auth.oauth_client.base_url' => $base, 'bherila-auth.oauth_client.client_id' => $client]);
        $users = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($users[1]['identity']['provider_account_url']);
        Http::assertNothingSent();
    }
}

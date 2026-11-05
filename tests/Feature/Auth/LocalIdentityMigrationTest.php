<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\LocalIdentityMigration;
use BWH\Auth\Models\PasskeyCredential;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use BWH\AuthManager\Services\IdentityCopy\TableSequence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EmbeddedAuthTestCase;

class LocalIdentityMigrationTest extends EmbeddedAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        [$id, $secret] = app(EmbeddedContext::class)->run(function (): array {
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Vora', [self::ORIGIN.'/oauth/callback']);

            return [(string) $client->getKey(), (string) $client->plain_secret];
        });
        config(['bherila-auth.oauth_client.client_id' => $id, 'bherila-auth.oauth_client.client_secret' => $secret]);
        config(['auth-manager.sign_in.code_after_password' => true, 'auth-manager.sign_in.code_only' => false]);
    }

    public function test_dry_run_is_deterministic_and_changes_no_accounts_credentials_bindings_or_sequence(): void
    {
        $source = User::factory()->approved()->admin()->create();
        $before = (array) DB::table('users')->find($source->id);
        $sequence = new TableSequence(DB::connection('auth-manager'));
        $floor = $sequence->next('users');

        $first = app(LocalIdentityMigration::class)->run(false);
        $this->assertSame($first, app(LocalIdentityMigration::class)->run(false));
        $this->assertSame('ready', $first['accounts'][0]['status']);
        $this->assertFalse($first['failed']);
        $this->assertSame($before, (array) DB::table('users')->find($source->id));
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertSame($floor, $sequence->next('users'));
        $this->withoutMockingConsoleOutput();
        try {
            $this->assertSame(0, $this->artisan('ops:local-identities', ['--json' => true]));
            $this->assertStringNotContainsString($source->password, Artisan::output());
        } finally {
            $this->mockConsoleOutput = true;
        }
    }

    public function test_apply_preserves_password_and_host_permissions_binds_exact_subject_and_resumes_without_overwrites(): void
    {
        $source = User::factory()->approved()->admin()->create();
        $before = (array) DB::table('users')->find($source->id);

        $report = app(LocalIdentityMigration::class)->run(true);

        $this->assertSame('migrated', $report['accounts'][0]['status']);
        $this->assertDatabaseHas('auth_manager_users', [
            'id' => $source->id, 'email' => $source->email, 'password' => $source->getRawOriginal('password'),
            'user_role' => 'user', 'disabled_at' => null, 'remember_token' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $source->id, 'oauth_provider' => 'identity', 'oauth_subject' => (string) $source->id,
        ]);
        $after = (array) DB::table('users')->find($source->id);
        unset($after['oauth_provider'], $after['oauth_subject'], $before['oauth_provider'], $before['oauth_subject']);
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('auth_manager_oauth_client_grants', ['subject' => $source->id]);
        $this->assertDatabaseHas('auth_manager_audit_log', ['user_id' => $source->id, 'event' => 'local_identity_imported']);

        // Provider decisions after migration are authoritative and never undone by a rerun.
        DB::table('auth_manager_users')->where('id', $source->id)->update(['disabled_at' => now(), 'password' => 'provider-changed-hash']);
        DB::table('auth_manager_oauth_client_grants')->delete();
        $again = app(LocalIdentityMigration::class)->run(true);
        $this->assertSame('unchanged', $again['accounts'][0]['status']);
        $this->assertDatabaseCount('auth_manager_users', 1);
        $this->assertDatabaseCount('local_identity_migrations', 1);
        $this->assertDatabaseCount('auth_manager_audit_log', 1);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        $this->assertDatabaseHas('auth_manager_users', ['password' => 'provider-changed-hash']);
    }

    public function test_disabled_and_deleted_accounts_keep_their_host_state_and_receive_disabled_provider_accounts_without_grants(): void
    {
        $states = [
            'disabled' => ['is_disabled' => true], 'deleted' => ['deleted_at' => now()],
        ];
        foreach ($states as $attributes) {
            User::factory()->approved()->create($attributes);
        }
        $report = app(LocalIdentityMigration::class)->run(true);
        foreach ($report['accounts'] as $position => $row) {
            $this->assertSame('migrated_disabled', $row['status']);
            $this->assertContains(array_keys($states)[$position], $row['disabled_reasons']);
            $this->assertNotNull(DB::table('auth_manager_users')->where('id', $row['user_id'])->value('disabled_at'));
        }
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        $this->assertDatabaseHas('users', ['is_disabled' => true]);
        $this->assertSame(1, User::withTrashed()->whereNotNull('deleted_at')->count());
        $this->assertSame(1, DB::table('auth_manager_users')->whereNotNull('deleted_at')->count());
    }

    public function test_an_incomplete_local_password_change_requires_local_recovery_before_migration(): void
    {
        $source = User::factory()->approved()->create(['force_change_pw' => true]);
        foreach ([false, true] as $apply) {
            $report = app(LocalIdentityMigration::class)->run($apply);
            $this->assertTrue($report['failed']);
            $this->assertSame('unsupported', $report['accounts'][0]['status']);
            $this->assertSame('local_password_change_required', $report['accounts'][0]['reason']);
        }
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertNull($source->fresh()->oauth_subject);
        $this->assertTrue($source->fresh()->force_change_pw);
    }

    public function test_conflicts_are_never_adopted_by_email_or_subject_and_other_accounts_can_resume(): void
    {
        $byId = User::factory()->approved()->create();
        $byEmail = User::factory()->approved()->create();
        $bound = User::factory()->approved()->create(['oauth_provider' => 'identity', 'oauth_subject' => '77']);
        $good = User::factory()->approved()->create();
        app(EmbeddedContext::class)->run(function () use ($byId, $byEmail): void {
            ProviderUser::factory()->create(['id' => $byId->id, 'email' => 'someone-else@example.test']);
            ProviderUser::factory()->create(['id' => 50, 'email' => $byEmail->email]);
        });

        $report = app(LocalIdentityMigration::class)->run(true);
        $this->assertTrue($report['failed']);
        $this->assertSame(['conflict', 'conflict', 'conflict', 'migrated'], array_column($report['accounts'], 'status'));
        $this->assertSame('provider_subject_occupied', $report['accounts'][0]['reason']);
        $this->assertSame('email_occupied_or_ambiguous', $report['accounts'][1]['reason']);
        $this->assertSame('binding_without_checkpoint', $report['accounts'][2]['reason']);
        $this->assertNull($byId->fresh()->oauth_subject);
        $this->assertNull($byEmail->fresh()->oauth_subject);
        $this->assertSame('77', $bound->fresh()->oauth_subject);
        $this->assertSame((string) $good->id, $good->fresh()->oauth_subject);
        $this->assertSame('unchanged', app(LocalIdentityMigration::class)->run(true, [$good->id])['accounts'][0]['status']);
        DB::table('local_identity_migrations')->where('user_id', $good->id)->update(['subject' => '50']);
        $this->assertSame('checkpoint_mismatch', app(LocalIdentityMigration::class)->run(true, [$good->id])['accounts'][0]['reason']);
    }

    public function test_passkeys_require_explicit_compatibility_and_keep_subject_handle_counter_and_source_credential(): void
    {
        $source = User::factory()->approved()->create();
        $key = PasskeyCredential::create([
            'user_id' => $source->id, 'credential_id' => 'credential-one', 'public_key' => base64_encode('opaque-cose-public-key'),
            'counter' => 29, 'rp_id' => '127.0.0.1', 'name' => 'Existing device', 'transports' => ['internal'],
        ]);
        $before = (array) DB::table('auth_passkeys')->find($key->id);
        $migration = app(LocalIdentityMigration::class);
        $this->assertSame('passkey_decision_required', $migration->run(false)['accounts'][0]['reason']);
        $this->assertSame('passkey_origin_or_rp_incompatible', $migration->run(false, copyPasskeys: true, rpId: 'other.example.test', origin: self::ORIGIN)['accounts'][0]['reason']);
        config(['auth-manager-embedded.overlay.bherila-auth.passkeys.user_verification' => 'discouraged']);
        $this->assertSame('passkey_origin_or_rp_incompatible', $migration->run(false, copyPasskeys: true, rpId: '127.0.0.1', origin: self::ORIGIN)['accounts'][0]['reason']);
        config(['auth-manager-embedded.overlay.bherila-auth.passkeys.user_verification' => 'preferred']);
        config(['auth-manager-embedded.overlay.bherila-auth.passkeys.allowed_origins' => ['https://additional.example.test']]);
        $this->assertSame('passkey_origin_or_rp_incompatible', $migration->run(false, copyPasskeys: true, rpId: '127.0.0.1', origin: self::ORIGIN)['accounts'][0]['reason']);
        config(['auth-manager-embedded.overlay.bherila-auth.passkeys.allowed_origins' => []]);

        $report = $migration->run(true, copyPasskeys: true, rpId: '127.0.0.1', origin: self::ORIGIN);
        $this->assertSame('migrated', $report['accounts'][0]['status']);
        $this->assertSame(1, $report['accounts'][0]['passkeys']);
        $this->assertDatabaseHas('auth_manager_webauthn_credentials', [
            'user_id' => $source->id, 'credential_id' => 'credential-one',
            'public_key' => $key->public_key, 'counter' => 29, 'rp_id' => '127.0.0.1',
        ]);
        $this->assertSame($before, (array) DB::table('auth_passkeys')->find($key->id));
    }

    public function test_incompatible_hashes_and_unknown_passkey_rp_fail_closed_unless_omission_is_explicit(): void
    {
        $badHash = User::factory()->approved()->create();
        DB::table('users')->where('id', $badHash->id)->update(['password' => 'not-a-supported-hash']);
        $unknownRp = User::factory()->approved()->create();
        PasskeyCredential::create([
            'user_id' => $unknownRp->id, 'credential_id' => 'legacy-credential', 'public_key' => base64_encode('key'), 'counter' => 3,
        ]);
        $report = app(LocalIdentityMigration::class)->run(true, copyPasskeys: true, rpId: '127.0.0.1', origin: self::ORIGIN);
        $this->assertSame(['password_reset_required', 'passkey_record_incompatible'], array_column($report['accounts'], 'reason'));
        $this->assertDatabaseCount('auth_manager_users', 0);
        $report = app(LocalIdentityMigration::class)->run(true, [$unknownRp->id], leavePasskeys: true);
        $this->assertSame('passkeys_left_local', $report['accounts'][0]['reason']);
        $this->assertDatabaseCount('auth_passkeys', 1);
        $this->assertDatabaseCount('auth_manager_webauthn_credentials', 0);
    }

    public function test_a_failure_after_writing_binding_rolls_back_everything_and_retry_is_unambiguous(): void
    {
        $source = User::factory()->approved()->create();
        DB::connection('auth-manager')->getSchemaBuilder()->drop('audit_log');
        $report = app(LocalIdentityMigration::class)->run(true);
        $this->assertSame('database_conflict', $report['accounts'][0]['reason']);
        $this->assertNull($source->fresh()->oauth_subject);
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        DB::connection('auth-manager')->getSchemaBuilder()->create('audit_log', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event');
            $table->string('auth_method');
            $table->boolean('succeeded');
            $table->timestamps();
        });
        $this->assertSame('migrated', app(LocalIdentityMigration::class)->run(true)['accounts'][0]['status']);
    }

    public function test_subject_reservation_includes_unselected_deleted_and_purged_source_ids(): void
    {
        $selected = User::factory()->approved()->create();
        User::factory()->approved()->create(['id' => 500, 'deleted_at' => now()]);
        $purged = User::factory()->approved()->create(['id' => 900]);
        $purged->forceDelete();
        $report = app(LocalIdentityMigration::class)->run(true, [$selected->id]);
        $this->assertGreaterThanOrEqual(901, $report['next_subject_floor']);
        $later = app(EmbeddedContext::class)->run(fn () => ProviderUser::factory()->create());
        $this->assertGreaterThanOrEqual(901, $later->id);
        $this->assertDatabaseMissing('auth_manager_users', ['id' => 500]);
    }

    public function test_separate_provider_databases_are_refused_before_any_write(): void
    {
        User::factory()->approved()->create();
        DB::connection('auth-manager')->setDatabaseName('a-different-database');
        try {
            app(LocalIdentityMigration::class)->run(true);
            $this->fail('Separate physical databases must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('same physical database', $exception->getMessage());
        }
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertNull(User::firstOrFail()->oauth_provider);
    }

    public function test_weaker_provider_authentication_is_refused_before_any_write(): void
    {
        User::factory()->approved()->create();
        config(['auth-manager.sign_in.code_only' => true]);
        try {
            app(LocalIdentityMigration::class)->run(true);
            $this->fail('Migration must not weaken local password and second-factor requirements.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('AUTH_MANAGER_REQUIRE_CODE_AFTER_PASSWORD=true', $exception->getMessage());
        }
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertNull(User::firstOrFail()->oauth_provider);
    }

    /** @return array<string, array{string, bool}> */
    public static function invalidClients(): array
    {
        $cases = [];
        foreach (['wrong_secret', 'public_client', 'host_provider', 'foreign_provider'] as $case) {
            $cases[$case.' dry run'] = [$case, false];
            $cases[$case.' apply'] = [$case, true];
        }

        return $cases;
    }

    #[DataProvider('invalidClients')]
    public function test_invalid_clients_are_refused_before_any_write(string $case, bool $apply): void
    {
        $source = User::factory()->approved()->create();
        $sequence = new TableSequence(DB::connection('auth-manager'));
        $before = $sequence->next('users');
        if ($case === 'wrong_secret') {
            config(['bherila-auth.oauth_client.client_secret' => 'wrong-client-secret']);
        } else {
            $change = match ($case) {
                'public_client' => ['secret' => null],
                'host_provider' => ['provider' => 'users'],
                default => ['provider' => 'a-foreign-provider'],
            };
            DB::table('auth_manager_oauth_clients')->where('id', config('bherila-auth.oauth_client.client_id'))->update($change);
        }

        try {
            app(LocalIdentityMigration::class)->run($apply);
            $this->fail('A client that cannot complete embedded OAuth must not accept migration writes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('active and confidential', $exception->getMessage());
            $this->assertStringNotContainsString('wrong-client-secret', $exception->getMessage());
        }
        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_oauth_client_grants', 0);
        $this->assertDatabaseCount('local_identity_migrations', 0);
        $this->assertNull($source->fresh()->oauth_provider);
        $this->assertSame($before, $sequence->next('users'));
    }
}

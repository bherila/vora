<?php

namespace Tests\Feature\Auth;

use App\Console\Commands\EmbeddedAdminCommand;
use App\Models\User;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmbeddedAuthTestCase;

class EmbeddedAdminCommandTest extends EmbeddedAuthTestCase
{
    public function test_bootstrap_creates_an_audited_provider_administrator_and_leaves_host_accounts_untouched(): void
    {
        $host = User::factory()->approved()->create(['email' => 'admin@example.test']);
        $before = (array) DB::table('users')->find($host->getKey());
        $connection = DB::getDefaultConnection();

        $this->artisan('ops:embedded-admin', ['email' => 'ADMIN@example.test', 'name' => 'Administrator'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $provider = DB::table('auth_manager_users')->sole();
        $this->assertSame('admin@example.test', $provider->email);
        $this->assertSame('Administrator', $provider->name);
        $this->assertSame('admin', $provider->user_role);
        $this->assertTrue(Hash::check(self::PASSWORD, $provider->password));
        $this->assertDatabaseHas('auth_manager_audit_log', [
            'user_id' => $provider->id,
            'acting_user_id' => null,
            'event' => 'embedded_admin_created',
            'auth_method' => 'console',
            'succeeded' => true,
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, (array) DB::table('users')->find($host->getKey()));
        $this->assertDatabaseCount('auth_audit_log', 0);
        $this->assertSame('web', auth()->getDefaultDriver());
        $this->assertSame($connection, DB::getDefaultConnection());
    }

    /** @return array<string, array{bool}> */
    public static function existingPeople(): array
    {
        return ['active' => [false], 'deleted' => [true]];
    }

    #[DataProvider('existingPeople')]
    public function test_an_existing_provider_directory_cannot_be_bootstrapped(bool $deleted): void
    {
        app(EmbeddedContext::class)->run(function () use ($deleted): void {
            $person = ProviderUser::factory()->create(['user_role' => 'user']);
            if ($deleted) {
                $person->delete();
            }
        });

        $this->artisan('ops:embedded-admin', ['email' => 'admin@example.test', 'name' => 'Administrator'])
            ->expectsOutputToContain('already contains people')
            ->assertFailed();

        $this->assertDatabaseCount('auth_manager_users', 1);
        $this->assertDatabaseMissing('auth_manager_users', ['user_role' => 'admin']);
        $this->assertDatabaseCount('auth_manager_audit_log', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_password_confirmation_mismatch_writes_no_identity_or_audit_record(): void
    {
        $this->artisan('ops:embedded-admin', ['email' => 'admin@example.test', 'name' => 'Administrator'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'a different password')
            ->assertFailed();

        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_audit_log', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_identity_created_while_passwords_are_entered_prevents_bootstrap(): void
    {
        $command = Mockery::mock(EmbeddedAdminCommand::class)->makePartial();
        $command->shouldReceive('argument')->with('email')->andReturn('admin@example.test');
        $command->shouldReceive('argument')->with('name')->andReturn('Administrator');
        $command->shouldReceive('secret')->with('Password')->once()->andReturn(self::PASSWORD);
        $command->shouldReceive('secret')->with('Confirm password')->once()->andReturnUsing(function (): string {
            ProviderUser::factory()->create(['user_role' => 'user']);

            return self::PASSWORD;
        });
        $command->shouldReceive('error')->once()->with('The provider directory already contains people. Use its administrator screens.');

        $this->assertSame(EmbeddedAdminCommand::FAILURE, $command->handle(app(EmbeddedContext::class)));

        $this->assertDatabaseCount('auth_manager_users', 1);
        $this->assertDatabaseMissing('auth_manager_users', ['user_role' => 'admin']);
        $this->assertDatabaseCount('auth_manager_audit_log', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_mode_refuses_to_create_a_provider_administrator(): void
    {
        config(['auth.sign_in' => 'local']);

        $this->artisan('ops:embedded-admin', ['email' => 'admin@example.test', 'name' => 'Administrator'])
            ->expectsOutputToContain('requires AUTH_SIGN_IN=embedded')
            ->assertFailed();

        $this->assertDatabaseCount('auth_manager_users', 0);
        $this->assertDatabaseCount('auth_manager_audit_log', 0);
        $this->assertDatabaseCount('users', 0);
    }
}

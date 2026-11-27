<?php

namespace Tests\Feature;

use App\Models\User;
use BWH\Auth\OAuth\Session\RememberedProviderLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RememberedProviderLoginCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_clears_every_guard_for_only_the_deleted_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $remembered = app(RememberedProviderLogins::class);
        foreach (['web', 'another-guard'] as $guard) {
            $remembered->put($guard, (string) $user->getKey(), 'example', '42', 3, str_repeat('a', 64), 5);
        }
        $remembered->put('web', (string) $other->getKey(), 'example', '43', 3, str_repeat('b', 64), 5);

        $user->delete();

        $this->assertDatabaseMissing('provider_remembered_logins', ['user_id' => (string) $user->getKey()]);
        $this->assertDatabaseHas('provider_remembered_logins', ['user_id' => (string) $other->getKey(), 'epoch' => 5]);
    }

    public function test_force_deletion_clears_remembered_provider_logins(): void
    {
        $user = User::factory()->create();
        app(RememberedProviderLogins::class)->put('web', (string) $user->getKey(), 'example', '42', 3, str_repeat('a', 64), 5);

        $user->forceDelete();

        $this->assertDatabaseMissing('provider_remembered_logins', ['user_id' => (string) $user->getKey()]);
    }
}

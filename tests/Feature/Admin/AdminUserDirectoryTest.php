<?php

namespace Tests\Feature\Admin;

use App\Models\InviteGrant;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdministrator(): User
    {
        $admin = User::factory()->admin()->create(['id' => 1]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_directory_pages_are_bounded_and_ordered_by_descending_id(): void
    {
        $this->signInAdministrator();
        $accounts = User::factory()->approved()->count(35)->create();
        $expected = $accounts->modelKeys();
        $expected[] = 1;
        rsort($expected);

        $first = $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(30, 'data');
        $second = $this->getJson('/api/admin/users?page=2')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(array_slice($expected, 0, 30), array_column($first->json('data'), 'id'));
        $this->assertSame(array_slice($expected, 30), array_column($second->json('data'), 'id'));
        $first->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 30, 'total' => 36, 'has_more' => true]);
        $second->assertJsonPath('meta.has_more', false)->assertJsonPath('counts.all', 36);
    }

    public function test_search_finds_accounts_outside_the_page_and_treats_wildcards_literally(): void
    {
        $this->signInAdministrator();
        $target = User::factory()->approved()->create(['name' => 'Different Real Name', 'display_name' => 'Older Match_%!', 'email' => 'old-target@example.test']);
        User::factory()->approved()->count(31)->create();

        foreach (['  DIFFERENT REAL  ', 'older match', 'OLD-TARGET@', '_%!', '%'] as $search) {
            $this->getJson('/api/admin/users?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)
                ->assertJsonPath('counts.all', 33);
        }
        $this->getJson('/api/admin/users?search=not-found')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1)->assertJsonPath('counts.all', 33);
    }

    public function test_status_filters_preserve_overlaps_and_global_counts_including_primary_admin(): void
    {
        $this->signInAdministrator();
        DB::table('users')->where('id', 1)->update(['is_admin' => false]);
        $pending = User::factory()->pendingApproval()->disabled()->create(['name' => 'Pending Disabled']);
        $restricted = User::factory()->approved()->create();
        $bannedAdmin = User::factory()->admin()->create(['banned_at' => now()]);
        $inactiveRestriction = User::factory()->approved()->create();
        $deleted = User::factory()->admin()->pendingApproval()->create(['banned_at' => now()]);
        $deleted->delete();
        UserRestriction::factory()->create(['user_id' => $restricted->id]);
        UserRestriction::factory()->create(['user_id' => $bannedAdmin->id, 'expires_at' => now()->addDay()]);
        UserRestriction::factory()->create(['user_id' => $inactiveRestriction->id, 'expires_at' => now()]);
        UserRestriction::factory()->create(['user_id' => $inactiveRestriction->id, 'lifted_at' => now()]);
        UserRestriction::factory()->create(['user_id' => $deleted->id]);

        $counts = ['all' => 6, 'pending' => 1, 'restricted' => 2, 'banned' => 1, 'admins' => 2, 'deleted' => 1];
        foreach (['pending' => [$pending->id], 'restricted' => [$bannedAdmin->id, $restricted->id],
            'banned' => [$bannedAdmin->id], 'admins' => [$bannedAdmin->id, 1], 'deleted' => [$deleted->id]] as $status => $ids) {
            $response = $this->getJson('/api/admin/users?status='.$status)->assertOk()->assertJsonPath('counts', $counts);
            $this->assertSame($ids, array_column($response->json('data'), 'id'));
        }
        $this->getJson('/api/admin/users?status=restricted&search=Pending')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('counts', $counts);
    }

    public function test_search_preserves_accents_instead_of_using_accent_insensitive_database_matching(): void
    {
        $this->signInAdministrator();
        $plain = User::factory()->approved()->create(['name' => 'Cafe', 'display_name' => 'Plain']);
        $accented = User::factory()->approved()->create(['name' => 'CAFÉ', 'display_name' => 'Accented']);

        $this->getJson('/api/admin/users?search=CAFE')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $plain->id);
        $this->getJson('/api/admin/users?'.http_build_query(['search' => 'café']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accented->id);
    }

    /** @return array<string, array{string, string}> */
    public static function unicodeSearchValues(): array
    {
        return [
            'uppercase name' => ['name', 'CAFÉ'],
            'lowercase name' => ['name', 'café'],
            'uppercase display name' => ['display_name', 'ÉLODIE'],
            'lowercase display name' => ['display_name', 'élodie'],
            'uppercase email' => ['email', 'MÜNCHEN@example.test'],
            'lowercase email' => ['email', 'münchen@example.test'],
        ];
    }

    #[DataProvider('unicodeSearchValues')]
    public function test_unicode_search_finds_off_page_accounts_in_either_case(string $field, string $value): void
    {
        $this->signInAdministrator();
        $target = User::factory()->approved()->create([$field => $value]);
        User::factory()->approved()->count(31)->create();
        $this->assertNotContains($target->id, array_column($this->getJson('/api/admin/users')->assertOk()->json('data'), 'id'));

        foreach ([mb_strtolower($value, 'UTF-8'), mb_strtoupper($value, 'UTF-8')] as $search) {
            $this->getJson('/api/admin/users?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)
                ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 30)->assertJsonPath('counts.all', 33);
        }
    }

    public function test_unicode_search_keeps_percent_underscore_and_escape_characters_literal(): void
    {
        $this->signInAdministrator();
        $target = User::factory()->approved()->create(['name' => 'CAFÉ_50%!']);
        User::factory()->approved()->create(['name' => 'CAFÉx50 more!']);
        User::factory()->approved()->create(['name' => 'CAFE_50%!']);

        foreach (['café_', 'café_50%', 'CAFÉ_50%!'] as $search) {
            $this->getJson('/api/admin/users?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        }
    }

    public function test_sqlite_unicode_function_survives_separate_read_write_connections_and_reconnection(): void
    {
        $connection = DB::build([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
        ]);
        try {
            $write = $connection->getPdo();
            $read = $connection->getReadPdo();
            $this->assertNotSame($write, $read);
            $connection->disconnect();
            $connection->reconnect();
            $reconnected = $connection->getPdo();
            $reconnectedRead = $connection->getReadPdo();
            $this->assertNotSame($write, $reconnected);
            $this->assertNotSame($read, $reconnectedRead);

            foreach ([$write, $read, $reconnected, $reconnectedRead] as $pdo) {
                $row = $pdo->query("SELECT vora_unicode_lower('CAFÉ') AS folded, vora_unicode_lower(NULL) AS missing, LOWER('CAFÉ') AS builtin")->fetch(\PDO::FETCH_ASSOC);
                $this->assertSame(['folded' => 'café', 'missing' => null, 'builtin' => 'cafÉ'], $row);
            }
        } finally {
            DB::purge($connection->getName());
        }
    }

    public function test_invite_aggregates_only_include_displayed_accounts_and_active_grants(): void
    {
        $this->signInAdministrator();
        $offPage = User::factory()->approved()->create();
        $accounts = User::factory()->approved()->count(30)->create();
        $target = $accounts->last();
        InviteGrant::factory()->create(['user_id' => $target->id, 'quantity' => 9, 'remaining' => 9]);
        InviteGrant::factory()->create(['user_id' => $target->id, 'quantity' => 5, 'remaining' => 2, 'expires_at' => now()->addDay()]);
        InviteGrant::factory()->create(['user_id' => $target->id, 'remaining' => 6, 'expires_at' => now()]);
        InviteGrant::factory()->create(['user_id' => $target->id, 'remaining' => 0]);
        InviteGrant::factory()->create(['user_id' => $offPage->id, 'remaining' => 100]);

        DB::enableQueryLog();
        try {
            $response = $this->getJson('/api/admin/users')->assertOk()->assertJsonPath('data.0.invite_balance', 11);
            $balanceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'SUM(remaining)'))->values();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $balanceQueries);
        $this->assertStringContainsString('user_id', $balanceQueries[0]['query']);
        $ids = array_slice($balanceQueries[0]['bindings'], 0, count($response->json('data')));
        $this->assertSame(array_column($response->json('data'), 'id'), $ids);
        $this->assertNotContains($offPage->id, $ids);
    }

    public function test_authorized_lookup_includes_off_page_and_deleted_accounts_with_current_details(): void
    {
        $this->signInAdministrator();
        $target = User::factory()->approved()->create();
        User::factory()->approved()->count(31)->create();
        InviteGrant::factory()->create(['user_id' => $target->id, 'remaining' => 7]);
        UserRestriction::factory()->create(['user_id' => $target->id, 'reason' => 'Synthetic review']);
        $target->delete();

        $this->getJson('/api/admin/users/'.$target->id)->assertOk()
            ->assertJsonPath('data.id', $target->id)->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.invite_balance', 7)->assertJsonPath('data.restrictions.0.reason', 'Synthetic review')
            ->assertDontSee('password')->assertDontSee('remember_token');
        $this->getJson('/api/admin/users/99999999')->assertNotFound();
    }

    public function test_list_and_lookup_require_an_eligible_administrator(): void
    {
        $target = $this->signInAdministrator();
        foreach ([User::factory()->approved()->create(), User::factory()->admin()->pendingApproval()->create(),
            User::factory()->admin()->disabled()->create()] as $actor) {
            $this->actingAs($actor)->getJson('/api/admin/users')->assertForbidden();
            $this->actingAs($actor)->getJson('/api/admin/users?'.http_build_query(['search' => 'CAFÉ']))->assertForbidden();
            $this->actingAs($actor)->getJson('/api/admin/users/'.$target->id)->assertForbidden();
        }
        auth()->logout();
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/admin/users?'.http_build_query(['search' => 'CAFÉ']))->assertUnauthorized();
        $this->getJson('/api/admin/users/'.$target->id)->assertUnauthorized();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): array
    {
        return ['long search' => [['search' => str_repeat('a', 101)], 'search'],
            'array search' => [['search' => ['name']], 'search'],
            'unknown status' => [['status' => 'active'], 'status'],
            'zero page' => [['page' => 0], 'page'], 'negative page' => [['page' => -1], 'page'],
            'fractional page' => [['page' => 1.5], 'page'], 'excessive page' => [['page' => 1000001], 'page']];
    }

    #[DataProvider('invalidQueries')]
    public function test_directory_query_parameters_are_validated(array $parameters, string $field): void
    {
        $this->signInAdministrator();
        $this->getJson('/api/admin/users?'.http_build_query($parameters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}

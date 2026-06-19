<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BanLegalHoldTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_banned_user_is_gated_to_the_ban_notice(): void
    {
        $user = User::factory()->banned()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('account.banned'));
    }

    #[Test]
    public function test_banned_user_can_view_ban_page_and_submit_appeal(): void
    {
        $user = User::factory()->banned()->create();

        $this->actingAs($user)->get('/account/banned')->assertOk();

        $this->actingAs($user)
            ->postJson('/api/account/appeal', ['message' => 'Please reinstate me.'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('Please reinstate me.', $user->ban_appeal_message);
        $this->assertNotNull($user->ban_appeal_at);
    }

    #[Test]
    public function test_banned_user_can_still_deactivate(): void
    {
        $user = User::factory()->banned()->create();

        $this->actingAs($user)->postJson('/api/account/deactivate', [])->assertOk();

        $this->assertTrue($user->refresh()->isDeactivated());
    }

    #[Test]
    public function test_non_banned_user_is_redirected_away_from_ban_page(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->get('/account/banned')->assertRedirect('/');
    }

    #[Test]
    public function test_ban_and_hide_makes_account_inactive(): void
    {
        $hidden = User::factory()->bannedAndHidden()->create();
        $visible = User::factory()->banned()->create();

        $this->assertFalse($hidden->isActive());
        $this->assertTrue($visible->isActive());

        // scopeActive mirrors isActive().
        $activeIds = User::query()->active()->pluck('id');
        $this->assertFalse($activeIds->contains($hidden->id));
        $this->assertTrue($activeIds->contains($visible->id));
    }

    #[Test]
    public function test_legal_hold_blocks_deletion_only(): void
    {
        $user = User::factory()->approved()->onLegalHold()->create();

        $this->actingAs($user)->postJson('/api/account/delete', [])->assertStatus(403);
        $this->assertNull($user->refresh()->deleted_at);

        // Deactivation is still allowed under a legal hold.
        $this->actingAs($user)->postJson('/api/account/deactivate', [])->assertOk();
        $this->assertTrue($user->refresh()->isDeactivated());
    }

    #[Test]
    public function test_admin_can_ban_and_unban_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->approved()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$target->id}/ban", ['reason' => 'Spam', 'hide_content' => true])
            ->assertOk();
        $target->refresh();
        $this->assertTrue($target->isBanned());
        $this->assertTrue($target->banHidesContent());

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/unban", [])->assertOk();
        $this->assertFalse($target->refresh()->isBanned());
    }

    #[Test]
    public function test_legal_hold_blocks_admin_purge(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->approved()->onLegalHold()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertStatus(403);
        $this->assertNull($target->refresh()->deleted_at);

        // Lifting the hold allows the purge to proceed.
        $target->forceFill(['legal_hold_at' => null])->save();
        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk();
        $this->assertNull(User::find($target->id));
    }

    #[Test]
    public function test_admin_can_issue_invites_to_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->approved()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$target->id}/invites", ['quantity' => 4])
            ->assertOk();

        $this->assertSame(4, app(InviteService::class)->availableBalance($target->refresh()));
    }
}

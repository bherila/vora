<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavbarHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_navbar_items_are_hydrated_from_blade(): void
    {
        $payload = $this->initialData($this->get('/')->assertOk()->getContent());

        $navbar = $payload['navbar'];

        $this->assertFalse($navbar['authenticated']);
        $this->assertSame(config('app.name'), $navbar['brand']['label']);
        $this->assertSame('/', $navbar['brand']['href']);
        $this->assertSame(['Home'], array_column($navbar['navItems'], 'label'));
        $this->assertSame(['Log in', 'Sign up'], array_column($navbar['guestMenuItems'], 'label'));
        $this->assertNull($navbar['adminMenu']);
        $this->assertNull($navbar['accountMenu']);
    }

    public function test_authenticated_navbar_items_are_hydrated_from_blade(): void
    {
        $user = User::factory()->approved()->create(['display_name' => 'Nova Vega']);

        $payload = $this->initialData($this->actingAs($user)->get('/me')->assertOk()->getContent());

        $navbar = $payload['navbar'];

        $this->assertTrue($navbar['authenticated']);
        $this->assertSame([
            'Feed',
            'Messages',
            'Profile',
            'Explore',
            'People',
            'Requests',
        ], array_column($navbar['navItems'], 'label'));
        $this->assertSame('/feed', $navbar['navItems'][0]['href']);
        $this->assertSame('/messages', $navbar['navItems'][1]['href']);
        $this->assertSame(0, $navbar['navItems'][1]['badge']);
        $this->assertSame('/me', $navbar['navItems'][2]['href']);
        $this->assertSame('/users/follow-requests', $navbar['navItems'][5]['href']);
        $this->assertSame(0, $navbar['navItems'][5]['badge']);
        // Persona-free users retain the direct avatar/profile link.
        $this->assertSame('Nova Vega', $navbar['accountMenu']['label']);
        $this->assertNull($navbar['accountMenu']['avatarUrl']);
        $this->assertSame('/me', $navbar['accountMenu']['profileHref']);
        $this->assertSame(['Your activity', 'Settings', 'Invites', 'Log out'], array_column($navbar['accountMenu']['items'], 'label'));
        $this->assertSame('/me/activity', $navbar['accountMenu']['items'][0]['href']);
        $this->assertSame([], $navbar['guestMenuItems']);
    }

    public function test_persona_user_account_menu_keeps_an_explicit_profile_link(): void
    {
        $user = User::factory()->approved()->create();
        Character::factory()->for($user)->create();

        $payload = $this->initialData($this->actingAs($user)->get('/me')->assertOk()->getContent());
        $items = $payload['navbar']['accountMenu']['items'];

        $this->assertSame(['Profile', 'Your activity', 'Settings', 'Invites', 'Log out'], array_column($items, 'label'));
        $this->assertSame('/me', $items[0]['href']);
        $this->assertSame('/me/activity', $items[1]['href']);
    }

    public function test_admin_navbar_menu_is_hydrated_from_blade(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = $this->initialData($this->actingAs($admin)->get('/me')->assertOk()->getContent());

        $navbar = $payload['navbar'];

        $this->assertSame('Admin', $navbar['adminMenu']['label']);
        $this->assertSame([
            'Users',
            'Invites & signups',
            'Invitation requests',
            'Interests',
            'Media review',
            'Duplicate clusters',
            'Abuse reports',
            'Story review',
            'Posts review',
            'Deleted content',
            'Static pages',
            'Audit log',
        ], array_column($navbar['adminMenu']['items'], 'label'));
        $this->assertSame('/admin/users', $navbar['adminMenu']['items'][0]['href']);
    }

    public function test_admin_menu_is_withheld_from_an_admin_who_cannot_log_in(): void
    {
        // is_admin set, but the account is not yet approved — every admin route is
        // blocked by the admin-only gate, so the layout must not surface the Admin
        // menu or open-report count to it either.
        $pendingAdmin = User::factory()->admin()->pendingApproval()->create();

        // /pending-approval sits behind `auth` only (no approval gate), so it renders
        // the layout for an account the admin routes themselves block — the exact
        // surface where the bare isAdmin() flag would leak the Admin menu.
        $payload = $this->initialData($this->actingAs($pendingAdmin)->get('/pending-approval')->assertOk()->getContent());

        $this->assertFalse($payload['navbar']['isAdmin']);
        $this->assertNull($payload['navbar']['adminMenu']);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}

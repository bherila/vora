<?php

namespace Tests\Feature;

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
            'Profile',
            'Explore',
            'Create',
            'People',
            'Requests',
        ], array_column($navbar['navItems'], 'label'));
        $this->assertSame('/users/follow-requests', $navbar['navItems'][4]['href']);
        $this->assertSame(0, $navbar['navItems'][4]['badge']);
        // The account menu now identifies the signed-in user (name + avatar)
        // rather than a generic "Account" label. The avatar + name link to /me;
        // "View profile" is dropped from the menu as redundant.
        $this->assertSame('Nova Vega', $navbar['accountMenu']['label']);
        $this->assertNull($navbar['accountMenu']['avatarUrl']);
        $this->assertSame('/me', $navbar['accountMenu']['profileHref']);
        $this->assertSame(['Settings', 'Invites', 'Log out'], array_column($navbar['accountMenu']['items'], 'label'));
        $this->assertSame([], $navbar['guestMenuItems']);
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
            'Abuse reports',
            'Story review',
            'Posts review',
            'Deleted content',
            'Static pages',
            'Audit log',
        ], array_column($navbar['adminMenu']['items'], 'label'));
        $this->assertSame('/admin/users', $navbar['adminMenu']['items'][0]['href']);
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

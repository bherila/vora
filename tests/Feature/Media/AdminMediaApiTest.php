<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminMediaApiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('get')->andReturn(null);
        });
    }

    public function test_admin_sees_all_media_with_moderation_fields(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $a = User::factory()->approved()->create();
        $b = User::factory()->approved()->create();
        Media::factory()->for($a)->unlisted()->create();
        Media::factory()->for($b)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/media')->assertOk();

        $response->assertJsonCount(2, 'data');
        // Admin view DOES include the internal review state.
        $response->assertJsonPath('data.0.moderation_status', 'pending');
        $this->assertArrayHasKey('user', $response->json('data.0'));
    }

    public function test_non_admin_cannot_access_admin_media(): void
    {
        // User id 1 is always an admin, so occupy it with a filler first.
        User::factory()->admin()->create();
        $user = User::factory()->approved()->create();
        $this->actingAs($user)->getJson('/api/admin/media')->assertForbidden();
    }

    public function test_admin_can_approve_and_reject(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'approve',
            'notes' => 'ok',
        ])->assertOk()->assertJsonPath('data.moderation_status', 'approved');

        $this->assertSame($admin->id, $media->fresh()->moderated_by_user_id);
        $this->assertSame('ok', $media->fresh()->moderation_notes);

        $this->actingAs($admin)->postJson("/api/admin/media/{$media->id}/moderate", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('data.moderation_status', 'rejected');
    }

    public function test_status_filter(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        Media::factory()->approved()->create();
        Media::factory()->create(); // pending

        $this->actingAs($admin)->getJson('/api/admin/media?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.moderation_status', 'pending');
    }
}

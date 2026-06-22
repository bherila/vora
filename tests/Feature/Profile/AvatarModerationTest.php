<?php

namespace Tests\Feature\Profile;

use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A profile picture is shown to other users only once an admin approves it. The
 * owner may preview their own pending upload, but it must never reach other
 * viewers (the avatar pointer is set at upload-completion, before review).
 */
class AvatarModerationTest extends TestCase
{
    use RefreshDatabase;

    private function mockSignedUrls(): void
    {
        // Slash-free token so it matches identically in JSON APIs and in the
        // @json-escaped Blade payload.
        $this->mock(FileStorageService::class, function ($mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('AVATAR_URL_TOKEN');
        });
    }

    private function userWithAvatar(bool $approved): User
    {
        $user = User::factory()->approved()->create();
        $picture = Media::factory()->for($user)->profilePicture();
        $picture = $approved ? $picture->approved()->create() : $picture->create();
        $user->forceFill(['profile_picture_media_id' => $picture->id])->save();

        return $user;
    }

    public function test_pending_avatar_is_hidden_from_other_viewers(): void
    {
        $this->mockSignedUrls();

        $viewer = User::factory()->approved()->create();
        $target = $this->userWithAvatar(approved: false);

        $response = $this->actingAs($viewer)->getJson('/api/users')->assertOk();

        $entry = collect($response->json('data'))->firstWhere('id', $target->id);
        $this->assertNotNull($entry, 'Target user should appear in the directory.');
        $this->assertNull($entry['avatar_url']);
    }

    public function test_approved_avatar_is_visible_to_other_viewers(): void
    {
        $this->mockSignedUrls();

        $viewer = User::factory()->approved()->create();
        $target = $this->userWithAvatar(approved: true);

        $response = $this->actingAs($viewer)->getJson('/api/users')->assertOk();

        $entry = collect($response->json('data'))->firstWhere('id', $target->id);
        $this->assertSame('AVATAR_URL_TOKEN', $entry['avatar_url']);
    }

    public function test_owner_sees_their_own_pending_avatar(): void
    {
        $this->mockSignedUrls();

        $owner = $this->userWithAvatar(approved: false);

        // /me hydrates the owner's own profile payload (and renders the navbar
        // avatar) with the viewer set to the owner, so the pending picture shows.
        $this->actingAs($owner)->get('/me')
            ->assertOk()
            ->assertSee('AVATAR_URL_TOKEN');
    }
}

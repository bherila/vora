<?php

namespace Tests\Feature\Account;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- #20 media

    public function test_remove_profile_picture_soft_deletes_the_media_and_keeps_object(): void
    {
        Storage::fake('photos');
        $user = User::factory()->approved()->create();
        $media = Media::factory()->profilePicture()->create(['user_id' => $user->id, 'disk' => 'photos']);
        Storage::disk('photos')->put($media->object_key, 'data');
        $user->forceFill(['profile_picture_media_id' => $media->id])->save();

        $this->actingAs($user)
            ->deleteJson('/api/account/profile-picture')
            ->assertOk();

        $this->assertNull($user->refresh()->profile_picture_media_id);
        $this->assertSoftDeleted('media', ['id' => $media->id]);
        Storage::disk('photos')->assertExists($media->object_key);
    }

    public function test_delete_if_unreferenced_keeps_media_still_in_use(): void
    {
        Storage::fake('photos');
        $user = User::factory()->approved()->create();
        $media = Media::factory()->profilePicture()->create(['user_id' => $user->id, 'disk' => 'photos']);
        $user->forceFill(['profile_picture_media_id' => $media->id])->save();

        app(MediaService::class)->deleteIfUnreferenced($media);

        // Still referenced by the user, so it must survive.
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_deleting_a_character_soft_deletes_it_and_keeps_avatar_media(): void
    {
        Storage::fake('photos');
        $user = User::factory()->approved()->create();
        $avatar = Media::factory()->profilePicture()->create(['user_id' => $user->id, 'disk' => 'photos']);
        Storage::disk('photos')->put($avatar->object_key, 'data');
        $character = Character::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Nova',
            'profile_picture_media_id' => $avatar->id,
        ]);

        $this->actingAs($user)->deleteJson("/api/characters/{$character->id}")->assertOk();

        $this->assertSoftDeleted('characters', ['id' => $character->id]);
        $this->assertDatabaseHas('media', ['id' => $avatar->id]);
        Storage::disk('photos')->assertExists($avatar->object_key);
    }

    public function test_prune_collects_unreferenced_ready_profile_pictures(): void
    {
        Storage::fake('photos');
        $user = User::factory()->approved()->create();

        $orphan = Media::factory()->profilePicture()->create(['disk' => 'photos', 'created_at' => now()->subDays(2)]);
        Storage::disk('photos')->put($orphan->object_key, 'data');

        $referenced = Media::factory()->profilePicture()->create(['user_id' => $user->id, 'disk' => 'photos', 'created_at' => now()->subDays(2)]);
        $user->forceFill(['profile_picture_media_id' => $referenced->id])->save();

        $this->artisan('media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $orphan->id]);
        Storage::disk('photos')->assertMissing($orphan->object_key);
        $this->assertDatabaseHas('media', ['id' => $referenced->id]);
    }

    // -------------------------------------------------------- #21 deactivation

    public function test_user_can_deactivate_and_is_hidden_then_reactivate(): void
    {
        $viewer = User::factory()->approved()->create();
        $target = User::factory()->approved()->create(['display_name' => 'Visible Vera']);

        // Visible before deactivation.
        $this->actingAs($viewer)->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id]);

        $this->actingAs($target)->postJson('/api/account/deactivate')->assertOk();
        $this->assertNotNull($target->refresh()->deactivated_at);

        // Hidden from the directory and the profile endpoint.
        $this->actingAs($viewer)->getJson('/api/users')
            ->assertOk()
            ->assertJsonMissing(['id' => $target->id]);
        $this->actingAs($viewer)->getJson("/api/users/{$target->id}")->assertNotFound();

        // Gated out of the app until reactivation.
        $this->actingAs($target)->get('/dashboard')->assertRedirect(route('account.deactivated'));
        $this->actingAs($target)->getJson('/api/interests')->assertStatus(403);

        // Reactivate restores access.
        $this->actingAs($target)->post('/account/reactivate')->assertRedirect('/');
        $this->assertNull($target->refresh()->deactivated_at);
        $this->actingAs($viewer)->getJson('/api/users')->assertJsonFragment(['id' => $target->id]);
    }

    public function test_deactivated_owner_media_is_excluded_from_explore(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });

        // Create the owner first so the viewer is not user id 1 (treated as admin,
        // which bypasses the discovery visibility filter).
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        Media::factory()->for($owner)->approved()->create([
            'upload_status' => 'ready',
            'audience' => Audience::Everyone,
        ]);

        $this->actingAs($viewer)->getJson('/api/explore')->assertOk()->assertJsonCount(1, 'data');

        $owner->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($viewer)->getJson('/api/explore')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ #21 deletion

    public function test_self_delete_soft_deletes_and_blocks_login(): void
    {
        // Another admin must exist (and user id 1 is always an admin) so the
        // deleter is not blocked by the last-admin guard.
        User::factory()->approved()->create(['is_admin' => true]);
        $user = User::factory()->approved()->create(['email' => 'gone@example.com']);

        $this->actingAs($user)->postJson('/api/account/delete')->assertOk();

        // Soft-deleted: hidden from normal queries (incl. the login lookup) but recoverable.
        $this->assertNull(User::query()->where('email', 'gone@example.com')->first());
        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertTrue(User::withTrashed()->find($user->id)->trashed());
    }

    public function test_admin_can_restore_a_soft_deleted_user(): void
    {
        $admin = User::factory()->approved()->create(['is_admin' => true]);
        $target = User::factory()->approved()->create();
        $target->delete();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/restore")->assertOk();

        $this->assertFalse(User::withTrashed()->find($target->id)->trashed());
    }

    public function test_admin_purge_removes_user_media_characters_and_objects(): void
    {
        Storage::fake('photos');
        $admin = User::factory()->approved()->create(['is_admin' => true]);
        $target = User::factory()->approved()->create();

        $gallery = Media::factory()->create(['user_id' => $target->id, 'disk' => 'photos']);
        Storage::disk('photos')->put($gallery->object_key, 'data');
        $avatar = Media::factory()->profilePicture()->create(['user_id' => $target->id, 'disk' => 'photos']);
        Storage::disk('photos')->put($avatar->object_key, 'data');
        $character = Character::query()->create([
            'user_id' => $target->id,
            'display_name' => 'Nova',
            'profile_picture_media_id' => $avatar->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")->assertOk();

        $this->assertNull(User::withTrashed()->find($target->id));
        $this->assertDatabaseMissing('media', ['id' => $gallery->id]);
        $this->assertDatabaseMissing('media', ['id' => $avatar->id]);
        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
        Storage::disk('photos')->assertMissing($gallery->object_key);
        Storage::disk('photos')->assertMissing($avatar->object_key);
    }

    // ----------------------------------------------------- review follow-ups

    public function test_direct_media_view_is_blocked_for_deactivated_owner(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
        });

        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create([
            'upload_status' => 'ready',
            'audience' => Audience::Everyone,
        ]);

        // Viewable via its share link before deactivation.
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$media->ulid}")->assertOk();

        $owner->forceFill(['deactivated_at' => now()])->save();

        // Hidden once the owner is deactivated, even with the direct link. Reads
        // as not-found so the link can't confirm the media ever existed.
        $this->actingAs($viewer)->getJson("/api/media/by-ulid/{$media->ulid}")->assertNotFound();
    }

    public function test_follow_inbox_hides_requests_from_deactivated_users(): void
    {
        $recipient = User::factory()->approved()->create();
        $requester = User::factory()->approved()->create();
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $this->actingAs($recipient)->getJson('/api/users/follow-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($recipient)->getJson('/api/users/follow-requests/count')->assertJsonPath('data.count', 1);

        $requester->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($recipient)->getJson('/api/users/follow-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($recipient)->getJson('/api/users/follow-requests/count')->assertJsonPath('data.count', 0);
    }

    public function test_deactivated_user_is_redirected_from_home_to_the_gate(): void
    {
        $user = User::factory()->approved()->create();
        $user->forceFill(['deactivated_at' => now()])->save();

        // The home page is public, but the global gate still redirects a
        // deactivated (logged-in) user to reactivate.
        $this->actingAs($user)->get('/')->assertRedirect(route('account.deactivated'));
    }

    public function test_primary_admin_cannot_self_delete(): void
    {
        // The first user is id 1, which is always treated as the primary admin.
        $primary = User::factory()->approved()->create(['is_admin' => true]);
        $this->assertSame(1, $primary->id);

        $this->actingAs($primary)->postJson('/api/account/delete')->assertForbidden();
        $this->assertFalse($primary->refresh()->trashed());
    }

    public function test_last_active_admin_cannot_self_delete(): void
    {
        // id 1 exists but is disabled, so it is not an active admin.
        User::factory()->approved()->create(['is_disabled' => true]);
        $admin = User::factory()->approved()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson('/api/account/delete')->assertForbidden();
        $this->assertFalse($admin->refresh()->trashed());
    }

    public function test_pending_admin_does_not_count_as_active_for_self_delete(): void
    {
        // id 1 is an admin but unapproved (cannot reach admin routes), so it
        // must not count as the remaining usable admin.
        User::factory()->create(['is_admin' => true]);
        $admin = User::factory()->approved()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson('/api/account/delete')->assertForbidden();
        $this->assertFalse($admin->refresh()->trashed());
    }

    public function test_cannot_accept_a_follow_request_from_a_deactivated_requester(): void
    {
        $recipient = User::factory()->approved()->create();
        $requester = User::factory()->approved()->create();
        $followRequest = FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $requester->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($recipient)
            ->postJson("/api/users/follow-requests/{$followRequest->id}/accept")
            ->assertNotFound();

        $this->assertSame('pending', $followRequest->refresh()->status);
    }
}

<?php

namespace Tests\Feature\Notifications;

use App\Models\Block;
use App\Models\Mute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_uses_the_same_account_gate_and_remains_available_without_community_modules(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        User::factory()->create(); // Keep the pending account distinct from the primary administrator.
        $this->actingAs(User::factory()->create(['approved_at' => null]))->get('/notifications')->assertRedirect('/pending-approval?source=login');
        config(['instance.profile' => 'event', 'instance.modules.community' => false, 'instance.modules.profiles' => false]);
        $this->actingAs(User::factory()->approved()->create())->get('/notifications')
            ->assertOk()->assertViewIs('notifications.index')->assertSee('notifications-page');
    }

    public function test_earlier_pages_and_read_all_keep_the_existing_recipient_block_and_mute_scope(): void
    {
        User::factory()->create();
        $viewer = User::factory()->approved()->create();
        $visibleActor = User::factory()->approved()->create();
        $blockedActor = User::factory()->approved()->create();
        $mutedActor = User::factory()->approved()->create();
        Block::create(['blocker_id' => $viewer->id, 'blocked_user_id' => $blockedActor->id]);
        Mute::create(['user_id' => $viewer->id, 'muted_user_id' => $mutedActor->id]);
        for ($index = 0; $index < 25; $index++) {
            $this->notification($viewer, $visibleActor);
        }
        $blocked = $this->notification($viewer, $blockedActor);
        $muted = $this->notification($viewer, $mutedActor);
        $foreign = $this->notification($visibleActor, $viewer);
        $this->actingAs($viewer)->getJson('/api/notifications?page=1')->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 25);
        $page = $this->getJson('/api/notifications?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
        $id = $page->json('data.0.id');
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk();
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        $this->assertNotNull(DatabaseNotification::findOrFail($id)->read_at);
        foreach ([$blocked, $muted, $foreign] as $hidden) {
            $this->postJson('/api/notifications/'.$hidden->id.'/read')->assertNotFound();
        }
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
        foreach ([$blocked, $muted, $foreign] as $hidden) {
            $this->assertNull($hidden->fresh()->read_at);
        }
    }

    private function notification(User $recipient, User $actor): DatabaseNotification
    {
        return $recipient->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => self::class,
            'data' => ['type' => 'post_comment', 'actor_name' => 'Example person', '_actor_user_id' => $actor->id, 'actor_id' => $actor->id, 'url' => '/notifications'],
        ]);
    }
}

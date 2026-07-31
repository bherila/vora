<?php

namespace Tests\Feature\Chat;

use App\Enums\ReportReason;
use App\Jobs\DeliverChatMessageNotification;
use App\Models\ChatMessage;
use App\Models\FollowRequest;
use App\Models\Mute;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ChatMessageWakeup;
use App\Services\Chat\ChatGate;
use App\Services\Chat\ChatService;
use App\Services\Privacy\BlockService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ChatSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_report_snapshots_only_exact_evidence_and_bounds_admin_context(): void
    {
        Queue::fake();
        User::factory()->admin()->create();
        [$alice, $ben] = $this->mutualUsers();
        $admin = User::factory()->admin()->create();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $messages = collect();
        foreach (range(1, 7) as $number) {
            $messages->push($service->send($alice, $conversation, (string) Str::ulid(), "Evidence {$number}"));
        }
        $reported = $messages->get(3);

        $this->actingAs($ben)->postJson('/api/reports', [
            'type' => 'chat_message',
            'id' => $reported->ulid,
            'reason' => ReportReason::Harassment->value,
            'details' => 'Repeated harassment.',
        ])->assertCreated();

        $report = Report::query()->sole();
        $this->assertSame([
            'body' => 'Evidence 4',
            'sent_at' => $reported->created_at->toIso8601String(),
            'conversation_id' => $conversation->ulid,
            'message_id' => $reported->ulid,
            'sender' => [
                'id' => $alice->public_ulid,
                'display_name' => $alice->display_name,
            ],
        ], $report->evidence);

        $adminPayload = $this->actingAs($admin)->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.evidence.body', 'Evidence 4')
            ->json('data.0');
        $this->assertCount(5, $adminPayload['adjacent_context']);
        $this->assertSame(1, collect($adminPayload['adjacent_context'])->where('reported', true)->count());

        // Moderators receive only report-scoped context, never ambient chat API access.
        $this->actingAs($admin)
            ->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();

        app(UserAccountService::class)->purge($alice);
        $report->refresh();
        $this->assertSame('Evidence 4', $report->evidence['body']);
        $this->actingAs($admin)->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.reportable', null)
            ->assertJsonPath('data.0.evidence.body', 'Evidence 4')
            ->assertJsonCount(0, 'data.0.adjacent_context');
    }

    public function test_wakeup_is_post_commit_coalesced_idempotent_and_contains_no_message_body(): void
    {
        Queue::fake();
        Notification::fake();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $first = $service->send($alice, $conversation, (string) Str::ulid(), 'Secret first body');
        $second = $service->send($alice, $conversation, (string) Str::ulid(), 'Secret second body');

        Queue::assertPushed(DeliverChatMessageNotification::class, 2);
        (new DeliverChatMessageNotification($first->ulid))->handle(app(ChatGate::class));
        (new DeliverChatMessageNotification($second->ulid))->handle(app(ChatGate::class));
        (new DeliverChatMessageNotification($second->ulid))->handle(app(ChatGate::class));

        $this->assertDatabaseCount('notifications', 1);
        $notification = DB::table('notifications')->first();
        $payload = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($conversation->ulid, $payload['conversation_id']);
        $this->assertSame($alice->public_ulid, $payload['actor_id']);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertStringNotContainsString('Secret', $notification->data);
        Notification::assertSentTo($ben, ChatMessageWakeup::class, 1);

        $missing = $this->actingAs($ben)
            ->getJson('/api/chat/conversations/'.Str::ulid())
            ->assertNotFound();
        app(BlockService::class)->block($ben, $alice);
        $hidden = $this->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();
        $this->assertSame($missing->getContent(), $hidden->getContent());
        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_wakeup_rechecks_read_mute_block_preference_and_account_state(): void
    {
        Queue::fake();
        Notification::fake();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);

        $read = $service->send($alice, $conversation, (string) Str::ulid(), 'Already read');
        $service->markRead($ben, $conversation, $read);
        (new DeliverChatMessageNotification($read->ulid))->handle(app(ChatGate::class));

        $muted = $service->send($alice, $conversation, (string) Str::ulid(), 'Muted');
        Mute::query()->create(['user_id' => $ben->id, 'muted_user_id' => $alice->id]);
        (new DeliverChatMessageNotification($muted->ulid))->handle(app(ChatGate::class));
        Mute::query()->delete();

        $preference = $service->send($alice, $conversation, (string) Str::ulid(), 'Preference off');
        $ben->forceFill(['notify_message' => false])->save();
        (new DeliverChatMessageNotification($preference->ulid))->handle(app(ChatGate::class));
        $ben->forceFill(['notify_message' => true])->save();

        $blocked = $service->send($alice, $conversation, (string) Str::ulid(), 'Blocked later');
        app(BlockService::class)->block($ben, $alice);
        (new DeliverChatMessageNotification($blocked->ulid))->handle(app(ChatGate::class));
        app(BlockService::class)->unblock($ben, $alice);

        $ben->forceFill(['deactivated_at' => now()])->save();
        (new DeliverChatMessageNotification($blocked->ulid))->handle(app(ChatGate::class));

        $this->assertDatabaseCount('notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_mute_suppresses_global_badge_but_retains_thread_unread_and_history(): void
    {
        Queue::fake();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $message = $service->send($alice, $conversation, (string) Str::ulid(), 'Still visible explicitly');
        Mute::query()->create(['user_id' => $ben->id, 'muted_user_id' => $alice->id]);

        $this->actingAs($ben)->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $this->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
        $this->getJson("/api/chat/conversations/{$conversation->ulid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->ulid);
    }

    public function test_outer_transaction_rollback_discards_message_and_after_commit_wakeup(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);

        try {
            DB::transaction(function () use ($service, $alice, $conversation): void {
                $service->send($alice, $conversation, (string) Str::ulid(), 'Roll this back');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected test rollback.
        }

        $this->assertSame(0, ChatMessage::query()->count());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_account_state_changes_invalidate_peers_and_use_a_neutral_tombstone(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $message = $service->send($alice, $conversation, (string) Str::ulid(), 'Retained history');
        $version = $ben->fresh()->chat_sync_version;

        $this->actingAs($alice)->postJson('/api/account/deactivate')->assertOk();
        $this->assertGreaterThan($version, $ben->fresh()->chat_sync_version);
        $this->actingAs($ben)->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertOk()
            ->assertJsonPath('data.other_user', null)
            ->assertJsonPath('data.may_send', false);
        $this->getJson("/api/chat/conversations/{$conversation->ulid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->ulid);

        $this->actingAs($alice)->post('/account/reactivate')->assertRedirect('/');
        $this->actingAs($ben)->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertJsonPath('data.other_user.id', $alice->public_ulid)
            ->assertJsonPath('data.may_send', true);

        $this->actingAs($admin)->postJson("/api/admin/users/{$alice->id}/ban", [
            'reason' => 'Safety review',
            'hide_content' => false,
        ])->assertOk();
        $this->actingAs($ben)->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertJsonPath('data.other_user', null)
            ->assertJsonPath('data.may_send', false);
        $this->actingAs($admin)->postJson("/api/admin/users/{$alice->id}/unban")->assertOk();
        $this->actingAs($ben)->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertJsonPath('data.may_send', true);
    }

    public function test_hard_purge_removes_live_chat_wakeups_and_notifications_but_retains_report_evidence(): void
    {
        Notification::fake();
        User::factory()->admin()->create();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $message = $service->send($alice, $conversation, (string) Str::ulid(), 'Retained report evidence');
        $this->assertDatabaseHas('jobs', ['queue' => 'chat-notifications']);

        (new DeliverChatMessageNotification($message->ulid))->handle(app(ChatGate::class));
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($ben)->postJson('/api/reports', [
            'type' => 'chat_message',
            'id' => $message->ulid,
            'reason' => ReportReason::Harassment->value,
        ])->assertCreated();

        app(UserAccountService::class)->purge($alice);

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('jobs', ['queue' => 'chat-notifications']);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('Retained report evidence', Report::query()->sole()->evidence['body']);
    }

    /** @return array{0: User, 1: User} */
    private function mutualUsers(): array
    {
        $alice = User::factory()->approved()->create();
        $ben = User::factory()->approved()->create();
        foreach ([[$alice, $ben], [$ben, $alice]] as [$requester, $recipient]) {
            FollowRequest::query()->create([
                'requester_id' => $requester->id,
                'recipient_id' => $recipient->id,
                'status' => FollowRequest::STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);
        }

        return [$alice, $ben];
    }
}

<?php

namespace Tests\Feature\Chat;

use App\Models\Block;
use App\Models\Character;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\Chat\ChatGate;
use App\Services\Chat\ChatService;
use App\Services\Privacy\BlockService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_receive_stable_opaque_public_identifiers(): void
    {
        $user = User::factory()->approved()->create();

        $this->assertTrue(Str::isUlid($user->public_ulid));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'public_ulid' => $user->public_ulid,
        ]);
    }

    public function test_only_mutual_account_follows_can_create_a_conversation(): void
    {
        [$alice, $ben] = $this->users();
        $service = app(ChatService::class);

        $this->follow($alice, $ben);

        try {
            $service->conversationBetween($alice, $ben);
            $this->fail('A one-way follow must not authorize chat.');
        } catch (DomainException $exception) {
            $this->assertSame('Messaging is unavailable.', $exception->getMessage());
        }

        $persona = Character::factory()->for($alice)->create(['is_linked' => false]);
        $this->follow($ben, $alice, $persona);

        $this->expectException(DomainException::class);
        $service->conversationBetween($alice, $ben);
    }

    public function test_repeated_creation_returns_one_pair_with_exactly_two_participants(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);

        $first = $service->conversationBetween($alice, $ben);
        $second = $service->conversationBetween($ben, $alice);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ChatConversation::query()->count());
        $this->assertEqualsCanonicalizing(
            [$alice->id, $ben->id],
            $first->participants()->pluck('user_id')->all(),
        );
    }

    public function test_send_is_synchronous_durable_and_idempotent_without_queue_work(): void
    {
        Queue::fake();
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $clientMessageId = (string) Str::ulid();

        $first = $service->send($alice, $conversation, $clientMessageId, '  Hello later  ');
        $second = $service->send($alice, $conversation, $clientMessageId, 'Hello later');

        $this->assertTrue($first->is($second));
        $this->assertSame('Hello later', $first->body);
        $this->assertSame(1, ChatMessage::query()->count());
        $this->assertSame(0, $this->participant($conversation, $alice)->unread_count);
        $this->assertSame(1, $this->participant($conversation, $ben)->unread_count);
        $this->assertNotNull($conversation->fresh()->last_message_at);
        $this->assertSame(2, $alice->fresh()->chat_sync_version);
        $this->assertSame(2, $ben->fresh()->chat_sync_version);
        Queue::assertNothingPushed();
    }

    public function test_reusing_an_idempotency_key_for_different_content_is_rejected(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $clientMessageId = (string) Str::ulid();

        $service->send($alice, $conversation, $clientMessageId, 'First');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('That message key has already been used.');
        $service->send($alice, $conversation, $clientMessageId, 'Changed');
    }

    public function test_unfollow_keeps_history_readable_but_disables_sending(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $gate = app(ChatGate::class);
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $message = $service->send($alice, $conversation, (string) Str::ulid(), 'Stored');

        FollowRequest::query()
            ->where('requester_id', $alice->id)
            ->where('recipient_id', $ben->id)
            ->delete();

        $this->assertTrue($gate->mayRead($alice, $conversation));
        $this->assertTrue($gate->mayRead($ben, $conversation));
        $this->assertFalse($gate->mayCreateOrSend($alice, $ben));
        $this->assertSame('Stored', $message->fresh()->body);
    }

    public function test_blocking_is_asymmetric_for_a_separate_persona_but_denies_account_evasion(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $gate = app(ChatGate::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $service->send($ben, $conversation, (string) Str::ulid(), 'Before block');
        $separate = Character::factory()->for($ben)->create(['is_linked' => false]);

        app(BlockService::class)->block($alice, $ben, $separate);

        $this->assertTrue($gate->mayRead($alice, $conversation));
        $this->assertFalse($gate->mayRead($ben, $conversation));
        $this->assertFalse($gate->mayCreateOrSend($alice, $ben));
        $this->assertFalse($gate->mayCreateOrSend($ben, $alice));
        $this->assertSame([$conversation->id], $gate
            ->constrainVisibleConversations(ChatConversation::query(), $alice)
            ->pluck('id')
            ->all());
        $this->assertSame([], $gate
            ->constrainVisibleConversations(ChatConversation::query(), $ben)
            ->pluck('id')
            ->all());
    }

    public function test_account_block_hides_for_blocker_denies_blocked_and_unblock_restores(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $gate = app(ChatGate::class);
        $blocks = app(BlockService::class);
        $conversation = $service->conversationBetween($alice, $ben);

        $block = $blocks->block($alice, $ben);

        $this->assertFalse($gate->mayRead($alice, $conversation));
        $this->assertFalse($gate->mayRead($ben, $conversation));
        $this->assertSame(1, Block::query()->count());

        $blocks->remove($block);

        $this->assertTrue($gate->mayRead($alice, $conversation));
        $this->assertTrue($gate->mayRead($ben, $conversation));
        $this->assertSame(3, $alice->fresh()->chat_sync_version);
        $this->assertSame(3, $ben->fresh()->chat_sync_version);
    }

    public function test_admin_has_no_ambient_private_conversation_access(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $admin = User::factory()->admin()->create();
        $service = app(ChatService::class);
        $gate = app(ChatGate::class);
        $conversation = $service->conversationBetween($alice, $ben);

        $this->assertFalse($gate->mayRead($admin, $conversation));
        $this->assertSame([], $gate
            ->constrainVisibleConversations(ChatConversation::query(), $admin)
            ->pluck('id')
            ->all());
    }

    public function test_visibility_is_applied_before_cursor_pagination(): void
    {
        $viewer = User::factory()->approved()->create();
        $service = app(ChatService::class);
        $gate = app(ChatGate::class);
        $visibleIds = [];

        foreach (range(1, 4) as $index) {
            $other = User::factory()->approved()->create();
            $this->follow($viewer, $other);
            $this->follow($other, $viewer);
            $conversation = $service->conversationBetween($viewer, $other);
            $service->send($other, $conversation, (string) Str::ulid(), "Message {$index}");

            if ($index <= 2) {
                app(BlockService::class)->block($viewer, $other);
            } else {
                $visibleIds[] = $conversation->id;
            }
        }

        $page = $gate
            ->constrainVisibleConversations(ChatConversation::query(), $viewer)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate(2);

        $this->assertCount(2, $page->items());
        $this->assertEqualsCanonicalizing($visibleIds, collect($page->items())->pluck('id')->all());
    }

    public function test_read_cursor_is_monotonic_and_updates_private_unread_state(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $first = $service->send($alice, $conversation, (string) Str::ulid(), 'One');
        $second = $service->send($alice, $conversation, (string) Str::ulid(), 'Two');

        $service->markRead($ben, $conversation, $first);
        $this->assertSame(1, $this->participant($conversation, $ben)->unread_count);

        $version = $ben->fresh()->chat_sync_version;
        $service->markRead($ben, $conversation, $first);
        $this->assertSame($version, $ben->fresh()->chat_sync_version);

        $service->markRead($ben, $conversation, $second);
        $participant = $this->participant($conversation, $ben);
        $this->assertSame(0, $participant->unread_count);
        $this->assertSame($second->id, $participant->last_read_message_id);
        $this->assertNull($this->participant($conversation, $alice)->last_read_message_id);
    }

    /** @return array{0: User, 1: User} */
    private function users(): array
    {
        return [
            User::factory()->approved()->create(),
            User::factory()->approved()->create(),
        ];
    }

    /** @return array{0: User, 1: User} */
    private function mutualUsers(): array
    {
        [$alice, $ben] = $this->users();
        $this->follow($alice, $ben);
        $this->follow($ben, $alice);

        return [$alice, $ben];
    }

    private function follow(User $requester, User $recipient, ?Character $character = null): void
    {
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $character?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    private function participant(ChatConversation $conversation, User $user): ChatParticipant
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}

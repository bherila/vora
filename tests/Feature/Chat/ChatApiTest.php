<?php

namespace Tests\Feature\Chat;

use App\Models\Character;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Privacy\BlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutual_accounts_create_and_retrieve_one_opaque_conversation(): void
    {
        [$alice, $ben] = $this->mutualUsers();

        $created = $this->actingAs($alice)->postJson('/api/chat/conversations', [
            'recipient_id' => $ben->public_ulid,
        ])->assertCreated()
            ->assertJsonPath('data.other_user.id', $ben->public_ulid)
            ->assertJsonPath('data.other_user.display_name', $ben->display_name)
            ->assertJsonPath('data.may_send', true)
            ->assertJsonPath('data.latest_message', null)
            ->assertJsonMissing(['id' => $ben->id]);

        $conversationId = $created->json('data.id');
        $this->assertTrue(Str::isUlid($conversationId));

        $this->actingAs($alice)->postJson('/api/chat/conversations', [
            'recipient_id' => $ben->public_ulid,
        ])->assertOk()->assertJsonPath('data.id', $conversationId);

        $this->assertSame(1, ChatConversation::query()->count());
    }

    public function test_persona_follow_and_every_ineligible_recipient_share_generic_unavailable_response(): void
    {
        [$alice, $ben] = $this->users();
        $persona = Character::factory()->for($ben)->create(['is_linked' => false]);
        $this->follow($alice, $ben, $persona);
        $this->follow($ben, $alice);

        $personaOnly = $this->actingAs($alice)->postJson('/api/chat/conversations', [
            'recipient_id' => $ben->public_ulid,
        ]);
        $missing = $this->actingAs($alice)->postJson('/api/chat/conversations', [
            'recipient_id' => (string) Str::ulid(),
        ]);

        $personaOnly->assertUnprocessable()->assertExactJson([
            'success' => false,
            'message' => 'Messaging is unavailable.',
        ]);
        $this->assertSame($personaOnly->getContent(), $missing->getContent());
    }

    public function test_send_retry_is_idempotent_and_payload_hides_internal_ids(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $conversation = app(ChatService::class)->conversationBetween($alice, $ben);
        $clientMessageId = (string) Str::ulid();

        $first = $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversation->ulid}/messages", [
            'client_message_id' => $clientMessageId,
            'body' => '  Hello asynchronously  ',
        ])->assertCreated()
            ->assertJsonPath('data.sender_id', $alice->public_ulid)
            ->assertJsonPath('data.client_message_id', $clientMessageId)
            ->assertJsonPath('data.body', 'Hello asynchronously')
            ->assertJsonPath('data.is_mine', true);

        $messageId = $first->json('data.id');

        $this->actingAs($alice)->postJson("/api/chat/conversations/{$conversation->ulid}/messages", [
            'client_message_id' => $clientMessageId,
            'body' => 'Hello asynchronously',
        ])->assertOk()->assertJsonPath('data.id', $messageId);

        $recipientPayload = $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.sender_id', $alice->public_ulid)
            ->assertJsonPath('data.0.client_message_id', null)
            ->json('data.0');

        $this->assertArrayNotHasKey('sender_user_id', $recipientPayload);
        $this->assertArrayNotHasKey('conversation_id', $recipientPayload);
        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_hidden_missing_nonparticipant_and_admin_reads_have_identical_payloads(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $outsider = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $missingUlid = (string) Str::ulid();

        $missing = $this->actingAs($alice)
            ->getJson("/api/chat/conversations/{$missingUlid}")
            ->assertNotFound();
        $outsiderResponse = $this->actingAs($outsider)
            ->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();
        $adminResponse = $this->actingAs($admin)
            ->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();

        app(BlockService::class)->block($alice, $ben);
        $hidden = $this->actingAs($alice)
            ->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();
        $denied = $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}")
            ->assertNotFound();

        $this->assertSame($missing->getContent(), $outsiderResponse->getContent());
        $this->assertSame($missing->getContent(), $adminResponse->getContent());
        $this->assertSame($missing->getContent(), $hidden->getContent());
        $this->assertSame($missing->getContent(), $denied->getContent());
    }

    public function test_unfollow_preserves_api_history_and_returns_generic_send_failure(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $message = $service->send($alice, $conversation, (string) Str::ulid(), 'Still here');

        FollowRequest::query()
            ->where('requester_id', $ben->id)
            ->where('recipient_id', $alice->id)
            ->delete();

        $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->ulid);
        $this->actingAs($ben)
            ->postJson("/api/chat/conversations/{$conversation->ulid}/messages", [
                'client_message_id' => (string) Str::ulid(),
                'body' => 'Cannot send',
            ])->assertUnprocessable()->assertExactJson([
                'success' => false,
                'message' => 'Messaging is unavailable.',
            ]);
    }

    public function test_history_and_incremental_queries_cross_boundaries_without_duplicates(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $messages = collect();

        foreach (range(1, 30) as $number) {
            $messages->push($service->send(
                $alice,
                $conversation,
                (string) Str::ulid(),
                "Message {$number}",
            ));
        }

        $firstPage = $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}/messages")
            ->assertOk();
        $this->assertCount(24, $firstPage->json('data'));
        $this->assertNotNull($firstPage->json('next_cursor'));

        $secondPage = $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}/messages?cursor="
                .urlencode($firstPage->json('next_cursor')))
            ->assertOk();

        $historyIds = collect($firstPage->json('data'))
            ->concat($secondPage->json('data'))
            ->pluck('id');
        $this->assertCount(30, $historyIds);
        $this->assertCount(30, $historyIds->unique());

        $after = $messages->first()->ulid;
        $incremental = $this->actingAs($ben)
            ->getJson("/api/chat/conversations/{$conversation->ulid}/messages?after={$after}")
            ->assertOk();
        $this->assertCount(24, $incremental->json('data'));
        $this->assertSame($messages->get(1)->ulid, $incremental->json('data.0.id'));
        $this->assertNotNull($incremental->json('next_cursor'));
    }

    public function test_sync_etag_changes_only_after_durable_chat_state_changes(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);

        $initial = $this->actingAs($ben)->getJson('/api/chat/sync')->assertOk();
        $etag = $initial->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->actingAs($ben)
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/chat/sync')
            ->assertStatus(304);

        $service->send($alice, $conversation, (string) Str::ulid(), 'Wake up later');

        $changed = $this->actingAs($ben)
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/chat/sync')
            ->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_inbox_filters_hidden_threads_before_opaque_cursor_boundaries(): void
    {
        $viewer = User::factory()->approved()->create();
        $service = app(ChatService::class);
        $visibleConversationIds = collect();

        foreach (range(1, 28) as $number) {
            $other = User::factory()->approved()->create();
            $this->follow($viewer, $other);
            $this->follow($other, $viewer);
            $conversation = $service->conversationBetween($viewer, $other);
            $service->send($other, $conversation, (string) Str::ulid(), "Inbox {$number}");

            if ($number <= 2) {
                app(BlockService::class)->block($viewer, $other);
            } else {
                $visibleConversationIds->push($conversation->ulid);
            }
        }

        $first = $this->actingAs($viewer)
            ->getJson('/api/chat/conversations')
            ->assertOk();
        $this->assertCount(24, $first->json('data'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->actingAs($viewer)
            ->getJson('/api/chat/conversations?cursor='.urlencode($first->json('next_cursor')))
            ->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));

        $actualIds = collect($first->json('data'))
            ->concat($second->json('data'))
            ->pluck('id');
        $this->assertEqualsCanonicalizing($visibleConversationIds->all(), $actualIds->all());
    }

    public function test_read_updates_only_callers_unread_count_and_global_count(): void
    {
        [$alice, $ben] = $this->mutualUsers();
        $service = app(ChatService::class);
        $conversation = $service->conversationBetween($alice, $ben);
        $first = $service->send($alice, $conversation, (string) Str::ulid(), 'One');
        $service->send($alice, $conversation, (string) Str::ulid(), 'Two');

        $this->actingAs($ben)
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
        $this->actingAs($alice)
            ->getJson('/api/chat/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->actingAs($ben)
            ->postJson("/api/chat/conversations/{$conversation->ulid}/read", [
                'message_id' => $first->ulid,
            ])->assertOk()->assertJsonPath('data.unread_count', 1);

        $this->actingAs($ben)
            ->getJson('/api/chat/unread-count')
            ->assertJsonPath('data.count', 1);
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
}

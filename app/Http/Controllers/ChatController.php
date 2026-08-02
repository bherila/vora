<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\CreateConversationRequest;
use App\Http\Requests\Chat\ListChatConversationsRequest;
use App\Http\Requests\Chat\ListChatMessagesRequest;
use App\Http\Requests\Chat\ReadChatMessageRequest;
use App\Http\Requests\Chat\SendChatMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\ChatGate;
use App\Services\Chat\ChatInbox;
use App\Services\Chat\ChatService;
use App\Support\ChatCursor;
use App\Support\ChatPresenter;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChatController extends Controller
{
    private const PAGE_SIZE = 24;

    public function __construct(
        private readonly ChatGate $gate,
        private readonly ChatInbox $inbox,
        private readonly ChatService $chat,
        private readonly ChatPresenter $presenter,
    ) {}

    public function page(): View
    {
        return view('chat.index');
    }

    public function index(ListChatConversationsRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $query = $this->gate
            ->constrainVisibleConversations(ChatConversation::query(), $viewer)
            ->join('chat_participants as inbox_chat_participants', function ($join) use ($viewer): void {
                $join->on('inbox_chat_participants.conversation_id', '=', 'chat_conversations.id')
                    ->where('inbox_chat_participants.user_id', $viewer->id);
            })
            ->select('chat_conversations.*', 'inbox_chat_participants.last_activity_at as inbox_activity_at')
            ->with($this->conversationRelations());

        $cursor = $request->validated('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $decoded = ChatCursor::decode($cursor, 'conversation');
            $query->where(function (Builder $boundary) use ($decoded): void {
                $boundary->where('inbox_chat_participants.last_activity_at', '<', $decoded['timestamp'])
                    ->orWhere(function (Builder $tie) use ($decoded): void {
                        $tie->where('inbox_chat_participants.last_activity_at', $decoded['timestamp'])
                            ->where('chat_conversations.ulid', '<', $decoded['ulid']);
                    });
            });
        }

        $items = $query
            ->orderByDesc('inbox_chat_participants.last_activity_at')
            ->orderByDesc('chat_conversations.ulid')
            ->limit(self::PAGE_SIZE + 1)
            ->get();
        $hasMore = $items->count() > self::PAGE_SIZE;
        $page = $items->take(self::PAGE_SIZE)->values();
        $last = $page->last();

        return response()->json([
            'success' => true,
            'data' => $page
                ->map(fn (ChatConversation $conversation): array => $this->presenter->conversation($conversation, $viewer)),
            'next_cursor' => $hasMore && $last instanceof ChatConversation
                ? ChatCursor::encode(
                    'conversation',
                    (string) $last->getAttribute('inbox_activity_at'),
                    $last->ulid,
                )
                : null,
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $viewer = $request->user()->fresh();
        $token = hash_hmac(
            'sha256',
            $viewer->id.':'.$viewer->chat_sync_version,
            (string) config('app.key'),
        );
        $etag = '"'.$token.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(status: 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => ['cursor' => $token],
        ])->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $viewer = $request->user();

        return response()->json([
            'success' => true,
            'data' => ['count' => $this->inbox->unreadCount($viewer)],
        ]);
    }

    public function store(CreateConversationRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $recipient = User::query()
            ->where('public_ulid', $request->validated('recipient_id'))
            ->first();

        if (! $recipient instanceof User) {
            return $this->unavailable();
        }

        try {
            $conversation = $this->chat->conversationBetween($viewer, $recipient);
        } catch (DomainException) {
            return $this->unavailable();
        }

        $conversation->load($this->conversationRelations());

        return response()->json([
            'success' => true,
            'data' => $this->presenter->conversation($conversation, $viewer),
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $viewer = $request->user();
        $resolved = $this->resolveVisible($viewer, $conversation);

        return response()->json([
            'success' => true,
            'data' => $this->presenter->conversation($resolved, $viewer),
        ]);
    }

    public function messages(ListChatMessagesRequest $request, string $conversation): JsonResponse
    {
        $viewer = $request->user();
        $resolved = $this->resolveVisible($viewer, $conversation);
        $query = ChatMessage::query()
            ->where('conversation_id', $resolved->id)
            ->with('sender');
        $after = $request->validated('after');

        if (is_string($after)) {
            $boundary = ChatMessage::query()
                ->where('conversation_id', $resolved->id)
                ->where('ulid', $after)
                ->first();
            if (! $boundary instanceof ChatMessage) {
                throw ValidationException::withMessages(['after' => 'The message cursor is invalid.']);
            }

            $query->where(function (Builder $newer) use ($boundary): void {
                $newer->where('created_at', '>', $boundary->created_at)
                    ->orWhere(function (Builder $tie) use ($boundary): void {
                        $tie->where('created_at', $boundary->created_at)
                            ->where('ulid', '>', $boundary->ulid);
                    });
            })->orderBy('created_at')->orderBy('ulid');
        } else {
            $cursor = $request->validated('cursor');
            if (is_string($cursor)) {
                $decoded = ChatCursor::decode($cursor, 'message');
                $query->where(function (Builder $older) use ($decoded): void {
                    $older->where('created_at', '<', $decoded['timestamp'])
                        ->orWhere(function (Builder $tie) use ($decoded): void {
                            $tie->where('created_at', $decoded['timestamp'])
                                ->where('ulid', '<', $decoded['ulid']);
                        });
                });
            }

            $query->orderByDesc('created_at')->orderByDesc('ulid');
        }

        $items = $query->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $items->count() > self::PAGE_SIZE;
        $page = $items->take(self::PAGE_SIZE)->values();
        $last = $page->last();

        return response()->json([
            'success' => true,
            'data' => $page->map(fn (ChatMessage $message): array => $this->presenter->message($message, $viewer)),
            'next_cursor' => $hasMore && $last instanceof ChatMessage
                ? ($after !== null
                    ? $last->ulid
                    : ChatCursor::encode(
                        'message',
                        $last->created_at?->format('Y-m-d H:i:s') ?? '',
                        $last->ulid,
                    ))
                : null,
        ]);
    }

    public function send(SendChatMessageRequest $request, string $conversation): JsonResponse
    {
        $viewer = $request->user();
        $resolved = $this->resolveVisible($viewer, $conversation);

        try {
            $message = $this->chat->send(
                $viewer,
                $resolved,
                $request->validated('client_message_id'),
                $request->validated('body'),
            );
        } catch (DomainException) {
            return $this->unavailable();
        }

        $message->load('sender');

        return response()->json([
            'success' => true,
            'data' => $this->presenter->message($message, $viewer),
        ], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function markRead(ReadChatMessageRequest $request, string $conversation): JsonResponse
    {
        $viewer = $request->user();
        $resolved = $this->resolveVisible($viewer, $conversation);
        $message = ChatMessage::query()
            ->where('conversation_id', $resolved->id)
            ->where('ulid', $request->validated('message_id'))
            ->first();

        if (! $message instanceof ChatMessage) {
            $this->notFound();
        }

        $this->chat->markRead($viewer, $resolved, $message);
        $participant = ChatParticipant::query()
            ->where('conversation_id', $resolved->id)
            ->where('user_id', $viewer->id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $participant->unread_count],
        ]);
    }

    private function resolveVisible(User $viewer, string $ulid): ChatConversation
    {
        $conversation = $this->gate
            ->constrainVisibleConversations(ChatConversation::query(), $viewer)
            ->where('chat_conversations.ulid', $ulid)
            ->with($this->conversationRelations())
            ->first();

        if (! $conversation instanceof ChatConversation) {
            $this->notFound();
        }

        return $conversation;
    }

    /** @return array<int, string> */
    private function conversationRelations(): array
    {
        return ['participants.user.profilePicture', 'latestMessage.sender'];
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Messaging is unavailable.',
        ], 422);
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Not found.',
        ], 404));
    }
}

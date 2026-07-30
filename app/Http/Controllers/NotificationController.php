<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\BlockGraph;
use App\Support\MuteGraph;
use App\Support\PaginationMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in user's in-app notifications. Scoped to the user's own via the
 * Notifiable relations, so a user can only read and mark their own.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $query = $viewer->notifications()->getQuery();
        BlockGraph::notificationsVisibleTo($query, $viewer);
        MuteGraph::excludeMutedNotifications($query, $viewer->id);
        $paginator = $query->paginate((int) config('media.page_size', 24));

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
                ->values(),
            'meta' => PaginationMeta::from($paginator),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $query = $viewer->unreadNotifications()->getQuery();
        BlockGraph::notificationsVisibleTo($query, $viewer);
        MuteGraph::excludeMutedNotifications($query, $viewer->id);

        return response()->json([
            'success' => true,
            'data' => ['count' => $query->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $viewer = $request->user();
        $query = $viewer->notifications()->getQuery()->whereKey($id);
        BlockGraph::notificationsVisibleTo($query, $viewer);
        MuteGraph::excludeMutedNotifications($query, $viewer->id);
        $notification = $query->first();
        if ($notification === null) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $query = $viewer->unreadNotifications()->getQuery();
        BlockGraph::notificationsVisibleTo($query, $viewer);
        MuteGraph::excludeMutedNotifications($query, $viewer->id);
        $query->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        unset($data['_actor_user_id'], $data['_actor_character_id']);

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? null,
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}

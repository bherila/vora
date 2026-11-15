<?php

namespace App\Http\Controllers;

use App\Http\Requests\Push\DeletePushSubscriptionRequest;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'public_key' => config('webpush.vapid.public_key'),
                'subscription_count' => $user instanceof User ? $user->pushSubscriptions()->count() : 0,
                'endpoint_registered' => $this->endpointRegistered($request),
            ],
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: ?string} $data */
        $data = $request->validated();
        $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
        );
        $this->dropOldestBeyondLimit($user, $data['endpoint']);

        return response()->json([
            'success' => true,
            'data' => ['subscription_count' => $user->pushSubscriptions()->count()],
        ]);
    }

    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var array{endpoint: string} $data */
        $data = $request->validated();
        $user->deletePushSubscription($data['endpoint']);

        return response()->json([
            'success' => true,
            'data' => ['subscription_count' => $user->pushSubscriptions()->count()],
        ]);
    }

    /**
     * Keep a bounded number of devices per account: each subscription is a
     * delivery request for every notification. The one just registered stays.
     */
    private function dropOldestBeyondLimit(User $user, string $keepEndpoint): void
    {
        $limit = max(1, (int) config('webpush.max_subscriptions_per_user', 10));
        $excess = $user->pushSubscriptions()->count() - $limit;
        if ($excess <= 0) {
            return;
        }

        $user->pushSubscriptions()
            ->where('endpoint', '!=', $keepEndpoint)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($excess)
            ->get()
            ->each->delete();
    }

    private function endpointRegistered(Request $request): bool
    {
        $user = $request->user();
        $endpoint = (string) $request->query('endpoint', '');

        return $user instanceof User
            && $endpoint !== ''
            && $user->pushSubscriptions()->where('endpoint', $endpoint)->exists();
    }
}

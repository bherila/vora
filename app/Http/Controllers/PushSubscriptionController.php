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
}

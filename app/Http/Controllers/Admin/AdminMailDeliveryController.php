<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminMailDeliveryIndexRequest;
use App\Models\MailDelivery;
use App\Support\PaginationMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class AdminMailDeliveryController extends Controller
{
    public function index(): View
    {
        return view('admin.mail-deliveries');
    }

    /**
     * Recent sends with their latest reported outcome, plus a seven-day summary.
     */
    public function apiIndex(AdminMailDeliveryIndexRequest $request): JsonResponse
    {
        $query = MailDelivery::query()->with('user:id,name,display_name')->orderByDesc('sent_at')->orderByDesc('id');
        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->validated('search')) {
            $query->where('recipient', 'like', '%'.addcslashes(mb_strtolower($search), '\\%_').'%');
        }
        $page = $query->paginate(50);
        $summary = MailDelivery::query()->where('sent_at', '>=', now()->subDays(7))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total);

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (MailDelivery $delivery): array => [
                'id' => $delivery->id,
                'type' => $delivery->type,
                'recipient' => $delivery->recipient,
                'user' => $delivery->user === null ? null : ['id' => $delivery->user->id, 'name' => $delivery->user->display_name ?: $delivery->user->name],
                'mailer' => $delivery->mailer,
                'status' => $delivery->status,
                'status_reason' => $delivery->status_reason,
                'sent_at' => $delivery->sent_at->toIso8601String(),
                'status_at' => $delivery->status_at?->toIso8601String(),
            ])->values(),
            'meta' => PaginationMeta::from($page),
            'summary' => $summary,
            'reports' => [
                'brevo' => (string) config('services.brevo.webhook_secret') !== '',
                'ses' => (string) config('services.ses.notification_topic_arn') !== '',
            ],
        ]);
    }
}

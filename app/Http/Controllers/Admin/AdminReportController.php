<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminActOnReportRequest;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminReportController extends Controller
{
    /**
     * Map of report-type aliases to their model classes.
     *
     * @var array<string, class-string<Model>>
     */
    private const TYPES = [
        'media' => Media::class,
        'story' => Story::class,
        'post' => Post::class,
        'character' => Character::class,
        'user' => User::class,
    ];

    /**
     * Admin abuse-report review page (mounts the React admin UI).
     */
    public function index(): View
    {
        return view('admin.reports');
    }

    /**
     * JSON list of reports for review, newest first, filtered by ?status=
     * (defaults to open). Each row carries the reporter, the reported item (with
     * its owner), and — once reviewed — the resolution.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $status = $request->query('status', ReportStatus::Open->value);

        $paginator = Report::query()
            ->with(['reporter', 'reviewer'])
            ->when(in_array($status, ReportStatus::values(), true), fn (Builder $q) => $q->where('status', $status))
            // Open first, then newest, so the live queue surfaces what needs action.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ReportStatus::Open->value])
            ->latest()
            ->paginate((int) config('media.page_size', 24));

        $data = collect($paginator->items())->map(fn (Report $report): array => $this->present($report))->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Take an action on a report: dismiss it, remove the reported item, or act on
     * the owning account (suspend or legal hold). Account-scoped actions also
     * remove the reported item. Every action resolves the report and clears the
     * other open reports filed against the same item.
     */
    public function act(AdminActOnReportRequest $request, Report $report): JsonResponse
    {
        $admin = $request->user();
        $action = $request->validated('action');
        $notes = $request->validated('notes');

        $item = $this->resolveReportable($report);
        $owner = $item instanceof Model ? $this->ownerOf($item) : null;

        // A user report targets the profile/account itself, not a deletable
        // content row. Account-scoped actions below are the reviewed paths for
        // suspending or placing that user on legal hold.
        if ($action === 'delete_item' && $item instanceof User) {
            return response()->json(['success' => false, 'message' => 'Use an account action for a reported user.'], 422);
        }

        // Guard account-scoped actions: never the acting admin or the primary
        // admin account.
        if (in_array($action, ['suspend_owner', 'legal_hold_owner'], true)) {
            if (! $owner instanceof User) {
                return response()->json(['success' => false, 'message' => 'The owning account is no longer available.'], 422);
            }
            if ($owner->id === $admin->id || $owner->id === 1) {
                return response()->json(['success' => false, 'message' => 'You cannot take account actions against this user.'], 422);
            }
        }

        $resolution = match ($action) {
            'dismiss' => 'Dismissed — no action taken',
            'delete_item' => 'Reported content removed',
            'suspend_owner' => 'Owner suspended; content removed',
            'legal_hold_owner' => 'Owner placed on legal hold; content removed',
            default => null,
        };

        if ($action !== 'dismiss') {
            $this->removeItem($item);
        }

        if ($action === 'suspend_owner' && $owner instanceof User) {
            $this->suspendOwner($owner, $admin, $notes);
        }

        if ($action === 'legal_hold_owner' && $owner instanceof User) {
            $this->legalHoldOwner($owner, $admin, $notes);
        }

        $newStatus = $action === 'dismiss' ? ReportStatus::Dismissed : ReportStatus::Resolved;
        $this->resolveReport($report, $newStatus, $admin, $resolution, $notes);

        // Clear the other open reports against the same item — one decision covers
        // them all.
        if ($action !== 'dismiss') {
            Report::query()
                ->open()
                ->whereKeyNot($report->id)
                ->where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->update([
                    'status' => ReportStatus::Resolved->value,
                    'reviewed_by_user_id' => $admin->id,
                    'reviewed_at' => now(),
                    'resolution' => 'Resolved with a related report',
                ]);
        }

        $report->load(['reporter', 'reviewer']);

        return response()->json(['success' => true, 'data' => $this->present($report)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Report $report): array
    {
        $item = $this->resolveReportable($report);
        $alias = $this->aliasFor($report->reportable_type);
        $owner = $item instanceof Model ? $this->ownerOf($item) : null;

        return [
            'id' => $report->id,
            'reason' => $report->reason->value,
            'reason_label' => $report->reason->label(),
            'details' => $report->details,
            'status' => $report->status->value,
            'resolution' => $report->resolution,
            'created_at' => $report->created_at?->toIso8601String(),
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'reporter' => $report->reporter instanceof User ? [
                'id' => $report->reporter->id,
                'display_name' => $report->reporter->display_name ?: $report->reporter->name,
                'email' => $report->reporter->email,
            ] : null,
            'reviewer' => $report->reviewer instanceof User ? [
                'id' => $report->reviewer->id,
                'display_name' => $report->reviewer->display_name ?: $report->reviewer->name,
            ] : null,
            'reportable' => $item instanceof Model ? [
                'type' => $alias,
                'id' => $item->getKey(),
                'label' => $this->labelFor($item),
                'href' => $this->hrefFor($alias, $item),
                'deleted' => $this->isTrashed($item),
                'owner' => $owner instanceof User ? [
                    'id' => $owner->id,
                    'display_name' => $owner->display_name ?: $owner->name,
                    'email' => $owner->email,
                    'is_banned' => $owner->isBanned(),
                    'is_on_legal_hold' => $owner->isOnLegalHold(),
                ] : null,
            ] : null,
        ];
    }

    /**
     * Resolve the report's polymorphic target including soft-deleted rows, so the
     * admin keeps context even after the item is removed.
     */
    private function resolveReportable(Report $report): ?Model
    {
        $alias = $this->aliasFor($report->reportable_type);
        $class = self::TYPES[$alias] ?? null;
        if ($class === null) {
            return null;
        }

        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        if ($class !== User::class) {
            $query->with('user');
        }

        return $query->find($report->reportable_id);
    }

    private function removeItem(?Model $item): void
    {
        if ($item instanceof Model && ! $item instanceof User && ! $this->isTrashed($item) && method_exists($item, 'delete')) {
            $item->delete();
        }
    }

    /**
     * Suspend (ban) the owner and hide their content. Mirrors
     * {@see AdminUserController::ban()}.
     */
    private function suspendOwner(User $owner, User $admin, ?string $notes): void
    {
        $owner->forceFill([
            'banned_at' => now(),
            'banned_by_user_id' => $admin->id,
            'ban_reason' => $notes,
            'ban_hides_content' => true,
        ])->save();
    }

    /**
     * Place a legal hold on the owner. Mirrors
     * {@see AdminUserController::legalHold()}.
     */
    private function legalHoldOwner(User $owner, User $admin, ?string $notes): void
    {
        $owner->forceFill([
            'legal_hold_at' => now(),
            'legal_hold_by_user_id' => $admin->id,
            'legal_hold_note' => $notes,
        ])->save();
    }

    private function resolveReport(Report $report, ReportStatus $status, User $admin, ?string $resolution, ?string $notes): void
    {
        $report->forceFill([
            'status' => $status->value,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'resolution' => $notes !== null && $notes !== '' ? trim($resolution.' — '.$notes) : $resolution,
        ])->save();
    }

    private function ownerOf(Model $item): ?User
    {
        if ($item instanceof User) {
            return $item;
        }

        $owner = $item->getAttribute('user');

        return $owner instanceof User ? $owner : null;
    }

    private function aliasFor(string $morphClass): string
    {
        foreach (self::TYPES as $alias => $class) {
            if ((new $class)->getMorphClass() === $morphClass || $class === $morphClass) {
                return $alias;
            }
        }

        return $morphClass;
    }

    private function labelFor(Model $item): string
    {
        if ($item instanceof Media) {
            return $item->title ?: $item->original_filename;
        }
        if ($item instanceof Story) {
            return $item->title ?: 'Untitled story';
        }
        if ($item instanceof Post) {
            return Str::limit((string) $item->body, 80) ?: 'Post';
        }
        if ($item instanceof Character) {
            return $item->display_name;
        }
        if ($item instanceof User) {
            return $item->display_name ?: $item->name;
        }

        return 'Reported item';
    }

    private function hrefFor(string $alias, Model $item): ?string
    {
        if ($item instanceof User) {
            return "/users/{$item->id}";
        }
        if ($item instanceof Character) {
            return "/users/{$item->user_id}";
        }

        $prefix = match ($alias) {
            'media' => '/m/',
            'story' => '/s/',
            'post' => '/p/',
            default => null,
        };
        $ulid = $item->getAttribute('ulid');

        return $prefix !== null && $ulid !== null ? $prefix.$ulid : null;
    }

    private function isTrashed(Model $item): bool
    {
        return method_exists($item, 'trashed') && $item->trashed();
    }
}

<?php

namespace App\Http\Controllers\Events;

use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventAttendeeAccessRequest;
use App\Http\Requests\Events\EventOrganizerIndexRequest;
use App\Models\Event;
use App\Models\User;
use App\Services\Auth\AgeEligibility;
use App\Support\PaginationMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventOrganizerController extends Controller
{
    public function __construct(private readonly AgeEligibility $eligibility) {}

    public function page(): View
    {
        return view('events.organizer');
    }

    public function index(EventOrganizerIndexRequest $request): JsonResponse
    {
        $query = User::query()->orderBy('display_name')->orderBy('id');
        $search = trim((string) $request->validated('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $users) => $users->where('display_name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }
        if ($request->validated('status') === 'pending') {
            $query->whereNull('approved_at')->active();
        } elseif ($request->validated('status') === 'approved') {
            $query->whereNotNull('approved_at')->active();
        }
        $page = $query->paginate(30);
        // Fetch memberships only for this page, rather than loading every
        // event's complete attendee list into the organizer response.
        $memberships = DB::table('event_user')->join('events', 'events.id', '=', 'event_user.event_id')
            ->where('events.visibility', EventVisibility::Specific->value)
            ->whereIn('event_user.user_id', $page->getCollection()->modelKeys())
            ->select('event_user.user_id', 'events.ulid')->get()->groupBy('user_id');

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn (User $user): array => $this->attendee($user,
                $memberships->get($user->id, collect())->pluck('ulid')->all())),
            'events' => Event::query()->orderBy('name')->orderBy('id')->get(['ulid', 'name', 'visibility']),
            'meta' => PaginationMeta::from($page),
        ]);
    }

    public function access(EventAttendeeAccessRequest $request, User $user): JsonResponse
    {
        $selected = $request->validated('event_ulids');
        DB::transaction(function () use ($user, $selected): void {
            // Match uploads and Event editing: lock the attendee before Events.
            $attendee = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($attendee->isActive() && ! $attendee->isBanned() && $attendee->isApproved()
                && $attendee->hasVerifiedEmail() && $this->eligibility->reason($attendee) === null,
                422, 'This attendee must complete verification, approval and eligibility checks before Event access can be changed.');
            $events = Event::query()->where('visibility', EventVisibility::Specific)->orderBy('id')->lockForUpdate()->get();
            // Recheck after locking: visibility may have changed since validation.
            abort_unless(count(array_intersect($selected, $events->pluck('ulid')->all())) === count($selected),
                422, 'An Event changed. Refresh the attendee list and try again.');
            foreach ($events as $event) {
                if (in_array($event->ulid, $selected, true)) {
                    $event->members()->syncWithoutDetaching([$attendee->id]);
                } else {
                    $event->members()->detach($attendee->id);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** @param list<string> $memberships
     * @return array<string, mixed>
     */
    private function attendee(User $user, array $memberships): array
    {
        $reason = match (true) {
            ! $user->isActive() || $user->isBanned() => 'account_unavailable',
            ! $user->hasVerifiedEmail() => 'email_verification',
            default => $this->eligibility->reason($user),
        };

        return [
            'id' => $user->id, 'display_name' => $user->display_name, 'email' => $user->email,
            'is_admin' => $user->isAdmin(),
            'approved' => $user->isApproved(), 'blocked_reason' => $reason,
            'can_approve' => ! $user->isApproved() && $reason === null,
            'can_manage_access' => $user->isApproved() && $reason === null,
            'event_ulids' => $memberships,
            'event_uploads_auto_approved' => (bool) $user->event_uploads_auto_approved,
        ];
    }
}

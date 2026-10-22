<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventSettingsRequest;
use App\Http\Requests\Events\EventUpdateRequest;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventAdminController extends Controller
{
    public function __construct(private readonly EventPresenter $presenter) {}

    public function store(EventUpdateRequest $request): JsonResponse
    {
        $event = $this->save($request, null);

        return response()->json(['success' => true, 'data' => $this->presenter->summary($event, $request->user())], 201);
    }

    public function update(EventUpdateRequest $request, Event $event): JsonResponse
    {
        $event = $this->save($request, $event);

        return response()->json(['success' => true, 'data' => $this->presenter->summary($event, $request->user())]);
    }

    public function settings(EventSettingsRequest $request, EventSettings $settings): JsonResponse
    {
        $settings->setFeaturedCuratorIds(array_map('intval', $request->validated('featured_curator_ids')));

        return response()->json(['success' => true, 'data' => $settings->payload($request->user())]);
    }

    private function save(EventUpdateRequest $request, ?Event $event): Event
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $event, $data): Event {
            // Uploads lock the account before the event. Lock all FK parents in
            // id order before the event so membership edits use the same order.
            $ids = array_map('intval', $data['member_ids'] ?? []);
            $lockIds = array_unique([...$ids, $request->user()->id]);
            $users = User::query()->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get();
            abort_unless($users->whereIn('id', $ids)->count() === count($ids) && $users->whereIn('id', $ids)->every(fn (User $user): bool => $user->isActive() && $user->isApproved()), 422, 'Only active approved attendees can be selected.');
            $event = $event === null ? new Event : Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $fields = Arr::except($data, ['member_ids']);
            foreach (['starts_at', 'ends_at'] as $field) {
                if (isset($fields[$field])) {
                    $fields[$field] = CarbonImmutable::parse($fields[$field])->utc();
                }
            }
            $event->fill($fields);
            if ($event->starts_at !== null && $event->ends_at !== null && $event->ends_at->lt($event->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
            }
            if (! $event->exists) {
                $event->created_by = $request->user()->id;
            }
            $event->save();
            if (array_key_exists('member_ids', $data)) {
                $event->members()->sync($ids);
            }

            return $event->refresh()->load('members:id');
        });
    }
}

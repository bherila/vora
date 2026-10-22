<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventAccess;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventSettings;
use App\Services\Media\MediaResponseService;
use App\Support\BlockGraph;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly EventPresenter $presenter,
        private readonly EventSettings $settings,
    ) {}

    public function page(Request $request, ?User $user = null): View
    {
        abort_if($user !== null && ($user->trashed() || ! $user->isActive() || ! $user->isApproved()
            || ! BlockGraph::canViewIdentity($request->user(), $user->id)), 404, 'Not found.');

        return view('events.index', ['initialData' => ['eventApp' => $this->settings->payload($request->user())]]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visibleTo(Event::query(), $request->user());
        if ($request->user()->isAdmin()) {
            $query->with('members:id');
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('starts_at')->orderBy('id')->get()->map(fn (Event $event): array => $this->presenter->summary($event, $request->user())),
        ]);
    }

    public function attendees(Request $request, MediaResponseService $media): JsonResponse
    {
        $query = User::query()->active()->whereNotNull('approved_at')->with('profilePicture');
        BlockGraph::visibleTo($query, $request->user(), 'users.id');

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('display_name')->orderBy('id')->get()->map(fn (User $user): array => [
                'id' => $user->id, 'display_name' => $user->display_name,
                'avatar_url' => UserPresenter::avatarUrl($user, $media, $request->user()),
            ]),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->settings->payload($request->user())]);
    }
}

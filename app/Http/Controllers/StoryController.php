<?php

namespace App\Http\Controllers;

use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Enums\StoryType;
use App\Enums\Visibility;
use App\Http\Requests\Story\SaveStoryGraphRequest;
use App\Http\Requests\Story\StoreStoryRequest;
use App\Http\Requests\Story\UpdateStoryRequest;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Services\Story\StoryService;
use App\Support\StoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoryController extends Controller
{
    public function __construct(private readonly StoryService $stories) {}

    /**
     * The signed-in user's stories workspace (library + editor).
     */
    public function page(): View
    {
        return view('user.stories');
    }

    /**
     * Shareable reader page (resolved client-side by ulid).
     */
    public function readerPage(string $ulid): View
    {
        return view('stories.show', ['ulid' => $ulid]);
    }

    /**
     * Stories the current user authors (owns or has accepted co-authorship of).
     */
    public function index(): JsonResponse
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $stories = Story::query()
            ->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhereHas('authors', function (Builder $a) use ($user): void {
                        $a->where('user_id', $user->id)->where('status', StoryAuthor::STATUS_ACCEPTED);
                    });
            })
            ->with(['user', 'interests', 'involvements.involvable', 'authors.user'])
            ->withCount('nodes')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stories->map(fn (Story $story): array => StoryPresenter::summary($story))->values(),
        ]);
    }

    public function store(StoreStoryRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validated();
        $status = StoryStatus::from($data['status'] ?? StoryStatus::Draft->value);

        $story = $user->stories()->create([
            'ulid' => (string) Str::ulid(),
            'title' => $data['title'],
            'type' => $data['type'],
            'status' => $status->value,
            'body' => $data['body'] ?? null,
            'visibility' => $data['visibility'] ?? Visibility::Users->value,
            'published_at' => $status === StoryStatus::Published ? now() : null,
        ]);

        $this->stories->ensureOwnerAuthor($story, $user);
        $this->stories->syncInterests($story, $request->interestIds());
        $this->stories->syncInvolvements($story, $this->involvementsInput($request->validated()));

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, $user)], 201);
    }

    /**
     * Full editor payload (authors only).
     */
    public function show(Story $story): JsonResponse
    {
        Gate::authorize('update', $story);

        $user = request()->user();

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, $user)]);
    }

    public function update(UpdateStoryRequest $request, Story $story): JsonResponse
    {
        Gate::authorize('update', $story);

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $story->title = $data['title'];
        }
        if (array_key_exists('body', $data)) {
            $story->body = $data['body'];
        }
        if (array_key_exists('visibility', $data)) {
            $story->visibility = Visibility::from($data['visibility']);
        }
        if (array_key_exists('status', $data)) {
            $status = StoryStatus::from($data['status']);
            $story->status = $status;
            if ($status === StoryStatus::Published && $story->published_at === null) {
                $story->published_at = now();
            }
        }

        // Editing the reader-visible text of an already-approved story must send
        // it back through review, so post-approval content can't reach readers
        // unreviewed (see StoryPolicy::view).
        $contentChanged = $story->isDirty(['title', 'body']);
        $story->save();
        if ($contentChanged) {
            $this->requeueModerationIfApproved($story);
        }

        if (array_key_exists('interest_ids', $data)) {
            $this->stories->syncInterests($story, $request->interestIds());
        }
        if (array_key_exists('involvements', $data)) {
            $this->stories->syncInvolvements($story, $this->involvementsInput($data));
        }

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, request()->user())]);
    }

    public function destroy(Story $story): JsonResponse
    {
        Gate::authorize('delete', $story);

        $story->delete();

        return response()->json(['success' => true, 'message' => 'Story deleted.']);
    }

    /**
     * Replace the CYOA graph (nodes + choices) in one save.
     */
    public function saveGraph(SaveStoryGraphRequest $request, Story $story): JsonResponse
    {
        Gate::authorize('update', $story);

        if ($story->type !== StoryType::Cyoa) {
            return response()->json(['success' => false, 'message' => 'This story is not a choose-your-own-adventure.'], 422);
        }

        /** @var list<array{key: string, title?: ?string, body?: ?string, is_start?: bool, position_x?: float, position_y?: float}> $nodes */
        $nodes = $request->validated('nodes', []);
        /** @var list<array{from: string, to?: ?string, label: string, position?: int}> $choices */
        $choices = $request->validated('choices', []);

        $this->stories->saveGraph($story, $nodes, $choices);
        // A graph save rewrites reader-visible passages, so re-queue review.
        $this->requeueModerationIfApproved($story);

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, request()->user())]);
    }

    /**
     * Resolve a shareable story by ulid, honouring visibility + moderation.
     */
    public function showByUlid(string $ulid): JsonResponse
    {
        $story = Story::query()->where('ulid', $ulid)->firstOrFail();
        Gate::authorize('view', $story);

        return response()->json([
            'success' => true,
            'data' => StoryPresenter::readerView($this->stories->loadForPresentation($story)),
        ]);
    }

    /**
     * Shared editor payload: full story plus per-viewer capabilities and the
     * named involvement options.
     *
     * @return array<string, mixed>
     */
    private function editorPayload(Story $story, ?User $user): array
    {
        $payload = StoryPresenter::editorView($this->stories->loadForPresentation($story));
        $payload['can_manage_authors'] = $user instanceof User && $user->can('manageAuthors', $story);
        $payload['involvable_options'] = $this->stories->involvableOptions($story);

        return $payload;
    }

    /**
     * Return an already-approved story to the review queue after its
     * reader-visible content changed. Pending/rejected stories are left as-is
     * (they are not reader-visible anyway).
     */
    private function requeueModerationIfApproved(Story $story): void
    {
        if (! $story->isApprovedContent()) {
            return;
        }

        $story->forceFill([
            'moderation_status' => ModerationStatus::Pending->value,
            'moderated_by_user_id' => null,
            'moderated_at' => null,
            'moderation_notes' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{type: string, id: int}>
     */
    private function involvementsInput(array $data): array
    {
        return collect($data['involvements'] ?? [])
            ->map(fn (array $entry): array => ['type' => (string) $entry['type'], 'id' => (int) $entry['id']])
            ->all();
    }
}

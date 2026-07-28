<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\ModerationStatus;
use App\Enums\StoryStatus;
use App\Enums\StoryType;
use App\Http\Requests\Story\SaveStoryGraphRequest;
use App\Http\Requests\Story\StoreStoryRequest;
use App\Http\Requests\Story\UpdateStoryRequest;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Services\Favorites\FavoriteService;
use App\Services\Privacy\PrivacyAuditor;
use App\Services\Story\StoryService;
use App\Support\StoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoryController extends Controller
{
    public function __construct(
        private readonly StoryService $stories,
        private readonly PrivacyAuditor $auditor,
        private readonly FavoriteService $favorites,
    ) {}

    /**
     * The dedicated story editor page (?edit=<id>). The story library and create
     * flow live on the profile now, so with no edit target this forwards to the
     * profile home.
     */
    public function page(Request $request): View|RedirectResponse
    {
        if ($request->query('edit') === null) {
            return redirect()->route('me');
        }

        return view('user.stories', ['initialData' => [
            'stories' => ['currentUserId' => $request->user()?->id],
        ]]);
    }

    /**
     * Shareable reader page (resolved client-side by ulid).
     */
    public function readerPage(string $ulid): View
    {
        $story = Story::query()->where('ulid', $ulid)->first();
        $this->authorizeOr404('view', $story);

        $viewer = request()->user();

        return view('stories.show', ['initialData' => [
            'storyReader' => StoryPresenter::readerView($this->stories->loadForPresentation($story)) + [
                'favorite_count' => $this->favorites->countFor($story),
                'favorited' => $viewer instanceof User
                    && $this->favorites->favoritedIdsFor($viewer, 'story', [$story->id]) !== [],
                // Anyone signed in who isn't the author can report the story.
                'can_report' => $viewer instanceof User && $story->user_id !== $viewer->id,
            ],
        ]]);
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

        return response()->json([
            'success' => true,
            'data' => $this->storiesPayload($user),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function storiesPayload(User $user): Collection
    {
        $stories = Story::query()
            ->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhereHas('authors', function (Builder $a) use ($user): void {
                        $a->where('user_id', $user->id)->where('status', StoryAuthor::STATUS_ACCEPTED);
                    });
            })
            ->with(['user', 'interests', 'involvements.involvable', 'authors.user', 'authors.character'])
            ->withCount('nodes')
            ->latest()
            ->get();

        return $stories->map(fn (Story $story): array => StoryPresenter::summary($story))->values();
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
            'audience' => $request->audience()->value,
            'discoverable' => $request->discoverable(),
            'published_at' => $status === StoryStatus::Published ? now() : null,
        ]);

        $this->stories->ensureOwnerAuthor($story, $user);
        $this->stories->syncInterests($story, $request->interestIds());
        $this->stories->syncInvolvements($story, $this->involvementsInput($request->validated()));

        $story->syncAudienceMembers(
            $request->audience() === Audience::SpecificPeople ? $request->audienceUserIds() : []
        );
        $this->auditor->recordCreation($story, $user, $story->privacySnapshot(), $request);

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, $user)], 201);
    }

    /**
     * Full editor payload (authors only).
     */
    public function show(Story $story): JsonResponse
    {
        $this->authorizeOr404('update', $story);

        $user = request()->user();

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, $user)]);
    }

    public function update(UpdateStoryRequest $request, Story $story): JsonResponse
    {
        // 'update' on $story is authorized in UpdateStoryRequest::authorize(), before
        // validation, so a non-author 404s rather than leaking the story via a 422.
        $data = $request->validated();
        $privacyBefore = $story->privacySnapshot();

        if (array_key_exists('title', $data)) {
            $story->title = $data['title'];
        }
        if (array_key_exists('body', $data)) {
            $story->body = $data['body'];
        }
        if (array_key_exists('audience', $data)) {
            $story->audience = $request->audience();
        }
        if (array_key_exists('discoverable', $data)) {
            $story->discoverable = $request->discoverable();
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
            $this->requeueModeration($story);
        }

        if (array_key_exists('interest_ids', $data)) {
            $this->stories->syncInterests($story, $request->interestIds());
        }
        if (array_key_exists('involvements', $data)) {
            $this->stories->syncInvolvements($story, $this->involvementsInput($data));
        }

        if (array_key_exists('audience', $data)
            || array_key_exists('discoverable', $data)
            || array_key_exists('audience_user_ids', $data)) {
            if ($story->audience === Audience::SpecificPeople) {
                // Only rewrite the allowlist when the request actually carries it.
                // A title/body save (or a discoverable toggle) that omits
                // audience_user_ids must not silently revoke every grant.
                if (array_key_exists('audience_user_ids', $data)) {
                    $story->syncAudienceMembers($request->audienceUserIds());
                }
            } else {
                // Leaving the SpecificPeople tier clears any stale grants.
                $story->syncAudienceMembers([]);
            }
            $this->auditor->record($story, $request->user(), $privacyBefore, $story->privacySnapshot(), $request);
        }

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, request()->user())]);
    }

    public function destroy(Story $story): JsonResponse
    {
        $this->authorizeOr404('delete', $story);

        $story->delete();

        return response()->json(['success' => true, 'message' => 'Story deleted.']);
    }

    /**
     * Replace the CYOA graph (nodes + choices) in one save.
     */
    public function saveGraph(SaveStoryGraphRequest $request, Story $story): JsonResponse
    {
        // 'update' on $story is authorized in SaveStoryGraphRequest::authorize(),
        // before validation, so a non-author 404s rather than leaking via a 422.
        if ($story->type !== StoryType::Cyoa) {
            return response()->json(['success' => false, 'message' => 'This story is not a choose-your-own-adventure.'], 422);
        }

        /** @var list<array{key: string, title?: ?string, body?: ?string, is_start?: bool, position_x?: float, position_y?: float}> $nodes */
        $nodes = $request->validated('nodes', []);
        /** @var list<array{from: string, to?: ?string, label: string, position?: int}> $choices */
        $choices = $request->validated('choices', []);

        // Only re-queue review when the graph's reader-visible content actually
        // changed; a no-op "Save graph" must not knock an approved story offline.
        if ($this->stories->saveGraph($story, $nodes, $choices)) {
            $this->requeueModeration($story);
        }

        return response()->json(['success' => true, 'data' => $this->editorPayload($story, request()->user())]);
    }

    /**
     * Resolve a shareable story by ulid, honouring visibility + moderation.
     */
    public function showByUlid(string $ulid): JsonResponse
    {
        $story = Story::query()->where('ulid', $ulid)->first();
        $this->authorizeOr404('view', $story);

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
     * Return an already-reviewed story (approved or rejected) to the pending
     * queue after its reader-visible content changed: approved content must be
     * re-reviewed before readers see the new version, and a rejected story gets
     * a path back into review once the author revises it. Already-pending
     * stories are left untouched.
     */
    private function requeueModeration(Story $story): void
    {
        if ($story->isPendingReview()) {
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

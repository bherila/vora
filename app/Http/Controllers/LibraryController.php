<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Enums\RestrictionCapability;
use App\Http\Requests\Library\LibraryCollectionRequest;
use App\Http\Requests\Library\LibraryItemRequest;
use App\Http\Requests\Library\LibraryListRequest;
use App\Http\Requests\Library\LibraryMembershipRequest;
use App\Http\Requests\Library\LibrarySaveRequest;
use App\Http\Requests\Library\LibraryShareRequest;
use App\Http\Requests\Library\LibraryUploadRequest;
use App\Models\Character;
use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\User;
use App\Services\Library\LibraryAccess;
use App\Services\Library\LibraryPresenter;
use App\Services\Library\LibraryService;
use App\Services\Media\AccountStorageUsage;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaUploadService;
use App\Services\Moderation\RestrictionGate;
use App\Support\PaginationMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LibraryController extends Controller
{
    private const ITEM_RELATIONS = ['owner', 'media', 'members', 'collections.members'];

    public function __construct(
        private readonly LibraryAccess $access,
        private readonly LibraryService $library,
        private readonly LibraryPresenter $presenter,
    ) {}

    public function page(Request $request, AccountStorageUsage $usage): View
    {
        return view('library.index', ['initialData' => ['library' => [
            'enabled' => (bool) $request->user()->canUseLibrary(),
            'storage_usage' => $usage->forUser($request->user()),
        ]]]);
    }

    public function index(LibraryListRequest $request): JsonResponse
    {
        $user = $request->user();
        $section = $request->validated('section', 'all');
        $query = $section === 'shared' ? $this->access->sharedItems($user) : $user->libraryItems()->getQuery();
        if ($ulid = $request->validated('collection_ulid')) {
            $collection = $this->collection($user, $ulid);
            $query = $collection->items()->getQuery();
        }
        if ($section === 'uploaded') {
            $query->where('source', 'upload');
        } elseif ($section === 'saved') {
            $query->where('source', 'saved');
        } elseif ($section === 'notes') {
            $query->whereNull('media_id');
        }
        if ($search = $request->validated('q')) {
            $query->where(fn (Builder $q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('body', 'like', '%'.$search.'%'));
        }
        $query->where(fn (Builder $visible) => $visible->where('library_items.user_id', $user->id)->orWhere('source', '!=', 'saved'));
        $restricted = app(RestrictionGate::class)->denies($user, RestrictionCapability::MediaView);
        $query->where(function (Builder $q) use ($user, $restricted): void {
            $q->where('library_items.user_id', $user->id)->orWhereNull('media_id')
                ->orWhereHas('media', function (Builder $media) use ($user, $restricted): void {
                    $media->where(function (Builder $acl) use ($user): void {
                        $acl->where('purpose', '!=', MediaPurpose::Event->value)
                            ->orWhere(fn (Builder $eventMedia) => $eventMedia->where('purpose', MediaPurpose::Event->value)->viewableBy($user));
                    })->where('upload_status', 'ready')->where(function (Builder $visible) use ($user, $restricted): void {
                        $visible->where('user_id', $user->id);
                        if (! $restricted) {
                            $visible->orWhere('moderation_status', 'approved');
                        }
                    });
                });
        });
        $page = $query->with(self::ITEM_RELATIONS)->latest('library_items.created_at')->orderByDesc('library_items.id')->paginate(24);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (LibraryItem $item): array => $this->presenter->item($item, $user))->values(),
            'meta' => PaginationMeta::from($page),
        ]);
    }

    public function collections(Request $request): JsonResponse
    {
        $user = $request->user();
        $collections = $this->access->collections($user)
            ->with(['owner', 'members', 'relatedCharacter.user'])->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $this->presenter->collections($collections, $user)]);
    }

    public function storeCollection(LibraryCollectionRequest $request): JsonResponse
    {
        $collection = $request->user()->libraryCollections()->create($this->collectionData($request));
        $collection->forceFill(['related_persona_linked' => $collection->related_character_id !== null])->save();

        return response()->json(['success' => true, 'data' => $this->presenter->collection($collection, $request->user())], 201);
    }

    public function updateCollection(LibraryCollectionRequest $request, string $ulid): JsonResponse
    {
        $collection = $request->user()->libraryCollections()->where('ulid', $ulid)->firstOrFail();
        $collection->update($this->collectionData($request));
        if ($request->has('related_persona_ulid')) {
            $collection->forceFill(['related_persona_linked' => $collection->related_character_id !== null])->save();
        }

        return response()->json(['success' => true, 'data' => $this->presenter->collection($collection->fresh(['members', 'relatedCharacter.user']), $request->user())]);
    }

    public function destroyCollection(Request $request, string $ulid): JsonResponse
    {
        $request->user()->libraryCollections()->where('ulid', $ulid)->firstOrFail()->delete();

        return response()->json(['success' => true]);
    }

    public function shareCollection(LibraryShareRequest $request, string $ulid): JsonResponse
    {
        $collection = $request->user()->libraryCollections()->where('ulid', $ulid)->firstOrFail();
        $ids = $request->validated('user_ids');
        abort_unless($request->user()->canUseLibrary() || array_diff($ids, $collection->members()->pluck('users.id')->all()) === [], 403, 'Library storage is not enabled.');
        $collection->members()->sync($ids);

        return response()->json(['success' => true]);
    }

    public function storeNote(LibraryItemRequest $request): JsonResponse
    {
        $item = DB::transaction(function () use ($request): LibraryItem {
            $item = $request->user()->libraryItems()->create([
                'source' => 'note', 'title' => $request->validated('title'), 'body' => $request->validated('body'),
            ]);
            $this->library->addToCollection($request->user(), $item, $request->validated('collection_ulid'));

            return $item;
        });

        return $this->itemResponse($request->user(), $item, 201);
    }

    public function save(LibrarySaveRequest $request): JsonResponse
    {
        $item = $request->validated('media_ulid') !== null
            ? $this->library->saveMedia($request->user(), $request->validated('media_ulid'), $request->validated('collection_ulid'))
            : $this->library->saveItem($request->user(), $request->validated('item_ulid'), $request->validated('collection_ulid'));

        return $this->itemResponse($request->user(), $item, 201);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $item = LibraryItem::query()->where('ulid', $ulid)->with(self::ITEM_RELATIONS)->firstOrFail();
        abort_unless($this->access->canViewItem($request->user(), $item), 404, 'Not found.');

        return response()->json(['success' => true, 'data' => $this->presenter->item($item, $request->user(), resolveHls: true)]);
    }

    public function updateItem(LibraryItemRequest $request, string $ulid): JsonResponse
    {
        $item = $request->user()->libraryItems()->where('ulid', $ulid)->firstOrFail();
        abort_if($item->media_id !== null && $request->has('body'), 422, 'Only notes have editable text.');
        $item->update($request->safe()->only(['title', 'body']));

        return $this->itemResponse($request->user(), $item);
    }

    public function shareItem(LibraryShareRequest $request, string $ulid): JsonResponse
    {
        $item = $request->user()->libraryItems()->where('ulid', $ulid)->firstOrFail();
        $ids = $request->validated('user_ids');
        abort_if($item->source === 'saved' && $ids !== [], 422, 'Saved items stay private in your Library.');
        abort_unless($request->user()->canUseLibrary() || array_diff($ids, $item->members()->pluck('users.id')->all()) === [], 403, 'Library storage is not enabled.');
        $item->members()->sync($ids);

        return response()->json(['success' => true]);
    }

    public function destroyItem(Request $request, string $ulid): JsonResponse
    {
        $item = $request->user()->libraryItems()->where('ulid', $ulid)->firstOrFail();
        $this->library->remove($request->user(), $item);

        return response()->json(['success' => true]);
    }

    public function addMembership(LibraryMembershipRequest $request, string $ulid): JsonResponse
    {
        $item = $request->user()->libraryItems()->where('ulid', $ulid)->firstOrFail();
        $this->library->addToCollection($request->user(), $item, $request->validated('collection_ulid'));

        return response()->json(['success' => true]);
    }

    public function removeMembership(Request $request, string $ulid, string $collectionUlid): JsonResponse
    {
        $item = $request->user()->libraryItems()->where('ulid', $ulid)->firstOrFail();
        $collection = $request->user()->libraryCollections()->where('ulid', $collectionUlid)->firstOrFail();
        $collection->items()->detach($item->id);

        return response()->json(['success' => true]);
    }

    public function upload(LibraryUploadRequest $request, MediaUploadService $uploads, MediaResponseService $responder): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $uploads): array {
            $result = $uploads->createPendingUpload(
                $request->user(), MediaType::from($request->validated('type')),
                $request->validated('filename'), $request->validated('content_type'), $request->validated('title'),
                Audience::SpecificPeople, [],
                wantsThumbnail: $request->boolean('has_thumbnail'),
                perceptualHash: $request->validated('perceptual_hash'), purpose: MediaPurpose::Library,
                discoverable: false, fileHash: $request->validated('file_hash'),
                expectedSizeBytes: (int) $request->validated('size'),
                thumbnailSizeBytes: $request->validated('thumbnail_size'),
            );
            $item = $this->library->referenceMedia($request->user(), $result['media']);
            $this->library->addToCollection($request->user(), $item, $request->validated('collection_ulid'));

            return $result;
        });

        return response()->json([
            'success' => true, 'data' => $responder->item($result['media']),
            'upload_url' => $result['upload_url'], 'upload_headers' => $result['upload_headers'],
            'thumbnail_upload_url' => $result['thumbnail_upload_url'], 'thumbnail_upload_headers' => $result['thumbnail_upload_headers'],
            'multipart' => [
                'enabled' => (bool) config('media.multipart.enabled', true) && $result['media']->type->isVideo(),
                'threshold_bytes' => (int) config('media.multipart.threshold_bytes'),
                'part_size_bytes' => (int) config('media.multipart.part_size_bytes'),
            ],
        ], 201);
    }

    public function personas(LibraryListRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $search = $request->validated('q', '') ?? '';
        $directUlid = Str::isUlid($search) ? strtoupper($search) : null;
        if ($directUlid === null && preg_match('#(?:^|/)c/([0-9A-HJKMNP-TV-Z]{26})(?:[/?\#]|$)#i', $search, $matches) === 1) {
            $directUlid = strtoupper($matches[1]);
        }
        $query = $this->access->personas($viewer)
            ->where(function (Builder $visible) use ($viewer, $directUlid): void {
                $visible->where('user_id', $viewer->id)
                    ->orWhere(fn (Builder $listed) => $listed->where('audience', 'everyone')->where('discoverable', true));
                if ($directUlid !== null) {
                    $visible->orWhere('ulid', $directUlid);
                }
            });
        if ($directUlid !== null) {
            $query->where('ulid', $directUlid);
        } elseif ($search !== '') {
            $query->where('display_name', 'like', '%'.$search.'%');
        }
        $characters = $query->orderBy('display_name')->limit(30)->get()
            ->map(fn (Character $character): array => ['ulid' => $character->ulid, 'display_name' => $character->display_name])->values();

        return response()->json(['success' => true, 'data' => $characters]);
    }

    private function collection(User $user, string $ulid): LibraryCollection
    {
        $collection = LibraryCollection::query()->where('ulid', $ulid)->with(['owner', 'members'])->firstOrFail();
        abort_unless($this->access->canViewCollection($user, $collection), 404, 'Not found.');

        return $collection;
    }

    /** @return array<string, mixed> */
    private function collectionData(LibraryCollectionRequest $request): array
    {
        $data = $request->safe()->only(['name', 'description']);
        if ($request->has('related_persona_ulid')) {
            $data['related_character_id'] = $request->relatedCharacter()?->id;
        }

        return $data;
    }

    private function itemResponse(User $user, LibraryItem $item, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->item($item->load(self::ITEM_RELATIONS), $user)], $status);
    }
}

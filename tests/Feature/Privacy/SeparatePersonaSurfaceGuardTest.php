<?php

namespace Tests\Feature\Privacy;

use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\User;
use App\Notifications\FollowedUserPosted;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Cross-surface guard for the Separate-persona privacy boundary.
 *
 * These assertions intentionally share one fixture. A visitor who can reach a
 * Separate persona must see the persona consistently on every reachable
 * surface, without any one endpoint supplying the missing owner correlation.
 */
class SeparatePersonaSurfaceGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Persona-reachable visitor surfaces covered below:
     *
     * - persona profile, media/stories/posts tabs
     * - media, story and post detail routes
     * - favorites, comments, followers, notifications and side rail history
     *
     * IMPORTANT: Any new route reachable from a persona profile must be added
     * to this inventory and the sweep below. Keep this count in sync so a
     * partial test edit fails visibly instead of silently shrinking coverage.
     */
    private const VISITOR_SURFACE_COUNT = 16;

    /**
     * @return array{
     *     owner: User,
     *     viewer: User,
     *     account_follower: User,
     *     persona: Character,
     *     media: Media,
     *     account_media: Media,
     *     post: Post,
     *     story: Story
     * }
     */
    private function scenario(): array
    {
        User::factory()->create(); // spacer so nobody under test is the id-1 admin
        $owner = User::factory()->approved()->create([
            'name' => 'Owner Correlation Sentinel',
            'display_name' => 'Owner Correlation Sentinel',
            'email' => 'owner-correlation-sentinel@example.test',
        ]);
        $viewer = User::factory()->approved()->create(['display_name' => 'Direct Persona Follower']);
        $accountFollower = User::factory()->approved()->create(['display_name' => 'Account Follower']);
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Independent Persona',
            'is_linked' => false,
        ]);

        $this->acceptFollow($viewer, $owner, $persona);
        $this->acceptFollow($accountFollower, $owner);

        $media = Media::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
            'title' => null,
            'original_filename' => 'owner-filename-correlation-sentinel.jpg',
            'object_key' => "uploads/{$owner->id}/owner-storage-correlation-sentinel.jpg",
        ]);
        $accountMedia = Media::factory()->for($owner)->approved()->create([
            'title' => 'Account Attachment Correlation Sentinel',
        ]);
        $post = Post::factory()->for($owner)->approved()->create([
            'body' => 'Persona-authored post',
            'character_id' => $persona->id,
        ]);
        $story = Story::factory()->for($owner)->readable()->create([
            'title' => 'Persona-authored story',
        ]);
        $story->authors()
            ->where('user_id', $owner->id)
            ->update(['character_id' => $persona->id]);

        // Simulate a legacy row from before the write-side identity-boundary
        // validation. Read paths must still scrub this correlation.
        $post->attachments()->create([
            'attachable_type' => $accountMedia->getMorphClass(),
            'attachable_id' => $accountMedia->id,
        ]);
        PostComment::factory()->for($post)->for($owner)->create([
            'body' => 'Persona-framed owner comment',
        ]);

        Favorite::query()->create([
            'user_id' => $owner->id,
            'favoritable_type' => $persona->getMorphClass(),
            'favoritable_id' => $persona->id,
        ]);

        return [
            'owner' => $owner,
            'viewer' => $viewer,
            'account_follower' => $accountFollower,
            'persona' => $persona,
            'media' => $media,
            'account_media' => $accountMedia,
            'post' => $post,
            'story' => $story,
        ];
    }

    private function acceptFollow(User $requester, User $recipient, ?Character $persona = null): void
    {
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $persona?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example/signed');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function initialData(string $html): array
    {
        preg_match('/<script id="initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'initial-data script not found');

        /** @var array<string, mixed> */
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_separate_persona_visitor_surfaces_do_not_expose_or_correlate_the_owner(): void
    {
        $fixture = $this->scenario();
        $owner = $fixture['owner'];
        $viewer = $fixture['viewer'];
        $accountFollower = $fixture['account_follower'];
        $persona = $fixture['persona'];
        $media = $fixture['media'];
        $post = $fixture['post'];
        $story = $fixture['story'];

        $profileHtml = $this->actingAs($viewer)
            ->get("/c/{$persona->ulid}")
            ->assertOk()
            ->getContent();
        $profile = $this->initialData($profileHtml)['personaProfile'];
        $this->assertNull($profile['owner']);
        $this->assertFalse($profile['is_linked']);

        $mediaList = $this->getJson("/api/c/{$persona->ulid}/media")
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonMissingPath('data.0.original_filename')
            ->assertJsonPath(
                'data.0.url',
                "/api/media/by-ulid/{$media->ulid}/asset/original",
            );
        $storyList = $this->getJson("/api/c/{$persona->ulid}/stories")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $story->ulid)
            ->assertJsonPath('data.0.owner.id', null)
            ->assertJsonPath('data.0.owner.display_name', 'Independent Persona')
            ->assertJsonMissingPath('data.0.authors.0.user_id')
            ->assertJsonMissingPath('data.0.authors.0.character_id');
        $postList = $this->getJson("/api/c/{$persona->ulid}/posts")
            ->assertOk()
            ->assertJsonPath('data.0.ulid', $post->ulid)
            ->assertJsonPath('data.0.author', null)
            ->assertJsonPath('data.0.as_character.display_name', 'Independent Persona');

        $mediaDetail = $this->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonMissingPath('data.original_filename')
            ->assertJsonPath('data.owner.id', null)
            ->assertJsonPath('data.owner.display_name', 'Independent Persona')
            ->assertJsonPath('data.owner.href', "/c/{$persona->ulid}")
            ->assertJsonPath(
                'data.url',
                "/api/media/by-ulid/{$media->ulid}/asset/original",
            );

        $favoriteCreate = $this->postJson('/api/favorites', [
            'type' => 'media',
            'id' => $media->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.favorite.label', 'Untitled media')
            ->assertJsonPath(
                'data.favorite.thumbnail_url',
                "/api/media/by-ulid/{$media->ulid}/asset/original",
            );
        $viewerFavorites = $this->getJson("/api/users/{$viewer->id}/favorites")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Untitled media')
            ->assertJsonPath(
                'data.0.thumbnail_url',
                "/api/media/by-ulid/{$media->ulid}/asset/original",
            );
        $ownerFavorites = $this->getJson("/api/users/{$owner->id}/favorites")
            ->assertOk()
            ->assertJsonMissing(['id' => $persona->id])
            ->assertJsonMissing(['label' => 'Independent Persona']);

        $postDetail = $this->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.author', null)
            ->assertJsonPath('data.as_character.display_name', 'Independent Persona')
            ->assertJsonCount(0, 'data.attachments')
            ->assertJsonMissing(['label' => 'Account Attachment Correlation Sentinel']);
        // PostController's page and API share findByUlidPayload(); sweep the
        // hydrated page too so that contract remains pinned at the route seam.
        $postPage = $this->get("/p/{$post->ulid}")
            ->assertOk()
            ->getContent();
        $this->assertNull($this->initialData($postPage)['postView']['author']);
        $comments = $this->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $persona->id)
            ->assertJsonPath('data.0.author.display_name', 'Independent Persona');

        $storyDetail = $this->getJson("/api/stories/by-ulid/{$story->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', null)
            ->assertJsonPath('data.owner.display_name', 'Independent Persona')
            ->assertJsonMissingPath('data.authors.0.user_id')
            ->assertJsonMissingPath('data.authors.0.character_id');
        // StoryController builds the page and API payloads independently, so
        // both routes belong in the sweep.
        $storyPage = $this->get("/s/{$story->ulid}")
            ->assertOk()
            ->getContent();
        $storyPagePayload = $this->initialData($storyPage)['storyReader'];
        $this->assertNull($storyPagePayload['owner']['id']);
        $this->assertSame('Independent Persona', $storyPagePayload['owner']['display_name']);

        $followers = $this->getJson("/api/characters/{$persona->id}/followers")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.viewer_is_following', true)
            ->assertJsonPath('data.followers.0.follower.id', $viewer->id)
            ->assertJsonMissingPath('data.followers.0.target')
            ->assertJsonMissing(['id' => $accountFollower->id]);

        (new NotifyFollowersOfPost($post))->handle();
        $notification = $viewer->notifications()->sole()->data;
        $this->assertSame('Independent Persona', $notification['actor_name']);
        $this->assertSame("/p/{$post->ulid}", $notification['url']);
        $this->assertArrayNotHasKey('actor_id', $notification);

        $notifications = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.data.actor_name', 'Independent Persona')
            ->assertJsonPath('data.0.data.url', "/p/{$post->ulid}")
            ->assertJsonMissingPath('data.0.data.actor_id');
        $sideRail = $this->getJson('/api/side-rail')
            ->assertOk()
            ->assertJsonPath('data.recently_visited.0.display_name', 'Independent Persona')
            ->assertJsonPath('data.recently_visited.0.href', "/c/{$persona->ulid}");

        $visitorPayloads = [
            'persona profile' => $profileHtml,
            'persona media list' => $mediaList->getContent(),
            'persona story list' => $storyList->getContent(),
            'persona post list' => $postList->getContent(),
            'media detail' => $mediaDetail->getContent(),
            'favorite create' => $favoriteCreate->getContent(),
            'viewer favorites' => $viewerFavorites->getContent(),
            'owner favorites' => $ownerFavorites->getContent(),
            'post detail API' => $postDetail->getContent(),
            'post detail page' => $postPage,
            'comments' => $comments->getContent(),
            'story detail API' => $storyDetail->getContent(),
            'story detail page' => $storyPage,
            'followers' => $followers->getContent(),
            'notifications' => $notifications->getContent(),
            'side rail recently visited' => $sideRail->getContent(),
        ];
        $this->assertCount(self::VISITOR_SURFACE_COUNT, $visitorPayloads);

        foreach ($visitorPayloads as $visitorPayload) {
            $this->assertStringNotContainsString('Owner Correlation Sentinel', $visitorPayload);
            $this->assertStringNotContainsString('owner-correlation-sentinel@example.test', $visitorPayload);
            $this->assertStringNotContainsString('owner-filename-correlation-sentinel.jpg', $visitorPayload);
            $this->assertStringNotContainsString('owner-storage-correlation-sentinel', $visitorPayload);
            $this->assertStringNotContainsString('Account Attachment Correlation Sentinel', $visitorPayload);
            $this->assertStringNotContainsString("/users/{$owner->id}", $visitorPayload);
        }
    }

    public function test_linked_and_owner_views_preserve_deliberately_attributable_context(): void
    {
        $this->fakeStorage();
        $fixture = $this->scenario();
        $owner = $fixture['owner'];
        $viewer = $fixture['viewer'];
        $media = $fixture['media'];
        $post = $fixture['post'];

        $linked = Character::factory()->for($owner)->create([
            'display_name' => 'Linked Persona',
            'is_linked' => true,
        ]);
        $linkedMedia = Media::factory()->for($owner)->approved()->create([
            'character_id' => $linked->id,
            'original_filename' => 'linked-owner-file.jpg',
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/media/by-ulid/{$linkedMedia->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.display_name', 'Owner Correlation Sentinel')
            ->assertJsonPath('data.owner.href', "/users/{$owner->id}")
            ->assertJsonMissingPath('data.original_filename');

        $this->actingAs($owner)
            ->getJson("/api/media/by-ulid/{$media->ulid}")
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'owner-filename-correlation-sentinel.jpg')
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.owner.href', '/me');

        $this->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.label', 'Account Attachment Correlation Sentinel');
        $this->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $owner->id)
            ->assertJsonPath('data.0.author.display_name', 'Owner Correlation Sentinel');
    }

    public function test_blocking_owner_leaves_every_separate_persona_surface_byte_identical(): void
    {
        $this->travelTo(now()->startOfSecond());
        $fixture = $this->scenario();
        $viewer = $fixture['viewer'];
        $owner = $fixture['owner'];
        $post = $fixture['post'];

        (new NotifyFollowersOfPost($post))->handle();
        $before = $this->personaSurfacePayloads($fixture, $viewer);

        $this->actingAs($viewer)
            ->postJson("/api/users/{$owner->id}/block")
            ->assertCreated();

        $this->assertSame($before, $this->personaSurfacePayloads($fixture, $viewer));
    }

    public function test_blocking_separate_persona_leaves_every_owner_surface_byte_identical(): void
    {
        $this->travelTo(now()->startOfSecond());
        $fixture = $this->scenario();
        $viewer = $fixture['viewer'];
        $owner = $fixture['owner'];
        $persona = $fixture['persona'];
        $accountMedia = $fixture['account_media'];
        $accountPost = Post::factory()->for($owner)->approved()->create([
            'body' => 'Human account post',
        ]);
        PostComment::factory()->for($accountPost)->for($owner)->create([
            'body' => 'Human account comment',
        ]);
        $accountStory = Story::factory()->for($owner)->readable()->create([
            'title' => 'Human account story',
        ]);
        $viewer->notify(new FollowedUserPosted($accountPost));

        $before = $this->ownerSurfacePayloads(
            $viewer,
            $owner,
            $accountMedia,
            $accountPost,
            $accountStory,
        );

        $this->actingAs($viewer)
            ->postJson("/api/characters/{$persona->id}/block")
            ->assertCreated();

        $this->assertSame(
            $before,
            $this->ownerSurfacePayloads(
                $viewer,
                $owner,
                $accountMedia,
                $accountPost,
                $accountStory,
            ),
        );
    }

    public function test_denial_sweeps_the_pinned_persona_inventory_under_every_owned_identity(): void
    {
        $this->travelTo(now()->startOfSecond());
        $fixture = $this->scenario();
        $owner = $fixture['owner'];
        $viewer = $fixture['viewer'];
        $persona = $fixture['persona'];
        $media = $fixture['media'];
        $post = $fixture['post'];
        $story = $fixture['story'];
        $viewerPersonas = Character::factory()->count(2)->for($viewer)->create(['is_linked' => false]);

        $this->actingAs($viewer)->postJson('/api/favorites', [
            'type' => 'media',
            'id' => $media->id,
        ])->assertCreated();
        (new NotifyFollowersOfPost($post))->handle();
        $this->actingAs($viewer)->get("/c/{$persona->ulid}")->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/users/{$viewer->id}/block")
            ->assertCreated();

        foreach ([null, ...$viewerPersonas->pluck('id')->all()] as $activeCharacterId) {
            $this->actingAs($viewer)->withSession(
                $activeCharacterId === null ? [] : ['active_character_id' => $activeCharacterId],
            );

            $surfaceStatuses = [
                $this->get("/c/{$persona->ulid}")->status(),
                $this->getJson("/api/c/{$persona->ulid}/media")->status(),
                $this->getJson("/api/c/{$persona->ulid}/stories")->status(),
                $this->getJson("/api/c/{$persona->ulid}/posts")->status(),
                $this->getJson("/api/media/by-ulid/{$media->ulid}")->status(),
                $this->getJson("/api/users/{$viewer->id}/favorites")
                    ->assertOk()
                    ->assertJsonMissing(['id' => $media->id])
                    ->status(),
                $this->getJson("/api/users/{$owner->id}/favorites")->status(),
                $this->getJson("/api/posts/by-ulid/{$post->ulid}")->status(),
                $this->get("/p/{$post->ulid}")->status(),
                $this->getJson("/api/posts/{$post->id}/comments")->status(),
                $this->getJson("/api/stories/by-ulid/{$story->ulid}")->status(),
                $this->get("/s/{$story->ulid}")->status(),
                $this->getJson("/api/characters/{$persona->id}/followers")->status(),
                $this->getJson('/api/notifications')
                    ->assertOk()
                    ->assertJsonMissing(['actor_name' => $persona->display_name])
                    ->status(),
                $this->getJson('/api/notifications/unread-count')
                    ->assertOk()
                    ->assertJsonPath('data.count', 0)
                    ->status(),
                $this->getJson('/api/side-rail')
                    ->assertOk()
                    ->assertJsonMissing(['href' => "/c/{$persona->ulid}"])
                    ->status(),
            ];
            $this->assertCount(self::VISITOR_SURFACE_COUNT, $surfaceStatuses);
            $this->assertSame(
                [404, 404, 404, 404, 404, 200, 404, 404, 404, 403, 404, 404, 404, 200, 200, 200],
                $surfaceStatuses,
            );

            $favorite = $this->postJson('/api/favorites', ['type' => 'media', 'id' => $media->id])
                ->assertNotFound();
            $missingFavorite = $this->postJson('/api/favorites', ['type' => 'media', 'id' => 999999])
                ->assertNotFound();
            $report = $this->postJson('/api/reports', [
                'type' => 'media',
                'id' => $media->id,
                'reason' => 'harassment',
            ])->assertNotFound();
            $missingReport = $this->postJson('/api/reports', [
                'type' => 'media',
                'id' => 999999,
                'reason' => 'harassment',
            ])->assertNotFound();
            $this->assertSame($missingFavorite->json('message'), $favorite->json('message'));
            $this->assertSame($missingReport->json('message'), $report->json('message'));
            $this->postJson("/api/posts/{$post->id}/reactions")->assertNotFound();
            $this->postJson("/api/posts/{$post->id}/comments", ['body' => 'blocked'])->assertNotFound();
            $this->postJson("/api/users/{$owner->id}/follow-requests")->assertNotFound();
            $this->postJson("/api/characters/{$persona->id}/follow")->assertNotFound();
        }
    }

    /**
     * @param  array{
     *     owner: User,
     *     viewer: User,
     *     account_follower: User,
     *     persona: Character,
     *     media: Media,
     *     account_media: Media,
     *     post: Post,
     *     story: Story
     * }  $fixture
     * @return array<string, string>
     */
    private function personaSurfacePayloads(array $fixture, User $viewer): array
    {
        $owner = $fixture['owner'];
        $persona = $fixture['persona'];
        $media = $fixture['media'];
        $post = $fixture['post'];
        $story = $fixture['story'];

        $profileHtml = $this->actingAs($viewer)->get("/c/{$persona->ulid}")->assertOk()->getContent();
        $postPage = $this->get("/p/{$post->ulid}")->assertOk()->getContent();
        $storyPage = $this->get("/s/{$story->ulid}")->assertOk()->getContent();

        $payloads = [
            'persona profile' => json_encode($this->initialData($profileHtml)['personaProfile'], JSON_THROW_ON_ERROR),
            'persona media list' => $this->getJson("/api/c/{$persona->ulid}/media")->assertOk()->getContent(),
            'persona story list' => $this->getJson("/api/c/{$persona->ulid}/stories")->assertOk()->getContent(),
            'persona post list' => $this->getJson("/api/c/{$persona->ulid}/posts")->assertOk()->getContent(),
            'favorite create' => $this->postJson('/api/favorites', [
                'type' => 'media',
                'id' => $media->id,
            ])->assertCreated()->getContent(),
            'media detail' => $this->getJson("/api/media/by-ulid/{$media->ulid}")->assertOk()->getContent(),
            'viewer favorites' => $this->getJson("/api/users/{$viewer->id}/favorites")->assertOk()->getContent(),
            // The original correlation sweep checks the owner's favorites
            // separately. It is an owner-account surface and correctly 404s
            // after an account block, so use the persona's discovery listing
            // for this byte-equality sweep of persona-reachable surfaces.
            'persona discovery' => $this->getJson('/api/explore/personas')->assertOk()->getContent(),
            'post detail API' => $this->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk()->getContent(),
            'post detail page' => json_encode($this->initialData($postPage)['postView'], JSON_THROW_ON_ERROR),
            'comments' => $this->getJson("/api/posts/{$post->id}/comments")->assertOk()->getContent(),
            'story detail API' => $this->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk()->getContent(),
            'story detail page' => json_encode($this->initialData($storyPage)['storyReader'], JSON_THROW_ON_ERROR),
            'followers' => $this->getJson("/api/characters/{$persona->id}/followers")->assertOk()->getContent(),
            'notifications' => $this->getJson('/api/notifications')->assertOk()->getContent(),
            'side rail recently visited' => $this->getJson('/api/side-rail')->assertOk()->getContent(),
        ];
        $this->assertCount(self::VISITOR_SURFACE_COUNT, $payloads);

        return $payloads;
    }

    /**
     * @return array<string, string>
     */
    private function ownerSurfacePayloads(
        User $viewer,
        User $owner,
        Media $media,
        Post $post,
        Story $story,
    ): array {
        $profileHtml = $this->actingAs($viewer)->get("/users/{$owner->id}")->assertOk()->getContent();
        $postPage = $this->get("/p/{$post->ulid}")->assertOk()->getContent();
        $storyPage = $this->get("/s/{$story->ulid}")->assertOk()->getContent();

        $payloads = [
            'owner profile' => json_encode($this->initialData($profileHtml)['followProfile'], JSON_THROW_ON_ERROR),
            'owner counts' => $this->getJson("/api/users/{$owner->id}/content-counts")->assertOk()->getContent(),
            'owner recent' => $this->getJson("/api/users/{$owner->id}/recent-content")->assertOk()->getContent(),
            'owner media' => $this->getJson("/api/users/{$owner->id}/media")->assertOk()->getContent(),
            'owner stories' => $this->getJson("/api/users/{$owner->id}/stories")->assertOk()->getContent(),
            'owner posts' => $this->getJson("/api/users/{$owner->id}/posts")->assertOk()->getContent(),
            'media detail' => $this->getJson("/api/media/by-ulid/{$media->ulid}")->assertOk()->getContent(),
            'post detail API' => $this->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk()->getContent(),
            'post detail page' => json_encode($this->initialData($postPage)['postView'], JSON_THROW_ON_ERROR),
            'comments' => $this->getJson("/api/posts/{$post->id}/comments")->assertOk()->getContent(),
            'story detail API' => $this->getJson("/api/stories/by-ulid/{$story->ulid}")->assertOk()->getContent(),
            'story detail page' => json_encode($this->initialData($storyPage)['storyReader'], JSON_THROW_ON_ERROR),
            'owner favorites' => $this->getJson("/api/users/{$owner->id}/favorites")->assertOk()->getContent(),
            'people directory' => $this->getJson('/api/users')->assertOk()->getContent(),
            'notifications' => $this->getJson('/api/notifications')->assertOk()->getContent(),
            'side rail recently visited' => $this->getJson('/api/side-rail')->assertOk()->getContent(),
        ];
        $this->assertCount(self::VISITOR_SURFACE_COUNT, $payloads);

        return $payloads;
    }
}

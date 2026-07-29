<?php

namespace Tests\Feature\Privacy;

use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\Favorite;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
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
     * @return array{
     *     owner: User,
     *     viewer: User,
     *     account_follower: User,
     *     persona: Character,
     *     media: Media,
     *     account_media: Media,
     *     post: Post
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
        $comments = $this->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $persona->id)
            ->assertJsonPath('data.0.author.display_name', 'Independent Persona');

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

        foreach ([
            $profileHtml,
            $mediaList->getContent(),
            $mediaDetail->getContent(),
            $favoriteCreate->getContent(),
            $viewerFavorites->getContent(),
            $ownerFavorites->getContent(),
            $postDetail->getContent(),
            $comments->getContent(),
            $followers->getContent(),
            $notifications->getContent(),
        ] as $visitorPayload) {
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
}

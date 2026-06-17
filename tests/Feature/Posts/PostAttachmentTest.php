<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function follow(User $follower, User $followee): void
    {
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $followee->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }

    /** Attach the author's own media to a factory post (which they can view). */
    private function attach(Post $post, Media $media): void
    {
        $post->attachments()->create([
            'attachable_type' => $media->getMorphClass(),
            'attachable_id' => $media->id,
        ]);
    }

    public function test_user_can_attach_their_own_media_and_an_interest(): void
    {
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->create();
        $interest = Interest::query()->create(['name' => 'Hiking']);

        $response = $this->actingAs($user)->postJson('/api/posts', [
            'body' => 'Look at this',
            'attachments' => [
                ['type' => 'media', 'id' => $media->id],
                ['type' => 'interest', 'id' => $interest->id],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'data.attachments');
        $this->assertSame(2, Post::query()->firstOrFail()->attachments()->count());
    }

    public function test_cannot_attach_another_users_media(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $foreign = Media::factory()->for($other)->create();

        $this->actingAs($user)->postJson('/api/posts', [
            'body' => 'Sneaky',
            'attachments' => [['type' => 'media', 'id' => $foreign->id]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('attachments.0.id');

        $this->assertSame(0, Post::query()->count(), 'the post is not created when an attachment is rejected');
    }

    public function test_cannot_attach_another_users_character(): void
    {
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $character = $other->characters()->create(['display_name' => 'Not yours']);

        $this->actingAs($user)->postJson('/api/posts', [
            'body' => 'Sneaky',
            'attachments' => [['type' => 'character', 'id' => $character->id]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('attachments.0.id');
    }

    public function test_intersection_hides_a_restricted_attachment_from_a_non_follower(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $this->follow($follower, $author);

        // A public, approved post that attaches the author's followers-only media.
        $media = Media::factory()->for($author)->approved()->audience(Audience::Followers)->create();
        $post = Post::factory()->for($author)->approved()->create(['audience' => Audience::Everyone]);
        $this->attach($post, $media);

        // Non-follower: sees the post, but the media attachment is filtered out.
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(0, 'data.attachments');

        // Follower (and the author) see it — the intersection lets it through.
        $this->actingAs($follower)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(1, 'data.attachments');
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(1, 'data.attachments');
    }

    public function test_cannot_attach_non_gallery_media(): void
    {
        $user = User::factory()->approved()->create();
        $avatar = Media::factory()->for($user)->profilePicture()->approved()->create();

        $this->actingAs($user)->postJson('/api/posts', [
            'body' => 'My avatar',
            'attachments' => [['type' => 'media', 'id' => $avatar->id]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('attachments.0.id');
    }

    public function test_draft_story_attachment_is_hidden_from_other_viewers(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        // Approved but still a DRAFT — not yet published, so non-authors can't read it.
        $story = Story::factory()->for($author)->approved()->create(['audience' => Audience::Everyone]);
        $post = Post::factory()->for($author)->approved()->create(['audience' => Audience::Everyone]);
        $post->attachments()->create([
            'attachable_type' => $story->getMorphClass(),
            'attachable_id' => $story->id,
        ]);

        // Mirrors StoryPolicy::view (requires published): hidden from others.
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(0, 'data.attachments');
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(1, 'data.attachments');
    }

    public function test_unapproved_attachment_is_hidden_from_other_viewers(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();

        // Everyone-audience media, but still pending review.
        $media = Media::factory()->for($author)->create(['audience' => Audience::Everyone]);
        $post = Post::factory()->for($author)->approved()->create(['audience' => Audience::Everyone]);
        $this->attach($post, $media);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(0, 'data.attachments');
        // The owner always sees their own attachment, review state notwithstanding.
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(1, 'data.attachments');
    }
}

<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Interest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    private function attachCharacter(Post $post, Character $character): void
    {
        $post->attachments()->create([
            'attachable_type' => $character->getMorphClass(),
            'attachable_id' => $character->id,
        ]);
    }

    public function test_user_can_attach_their_own_media_and_an_interest(): void
    {
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->approved()->create();
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

    public function test_intersection_hides_a_restricted_character_attachment(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($author)->audience(Audience::SpecificPeople)->create();
        $post = Post::factory()->for($author)->approved()->create(['audience' => Audience::Everyone]);
        $this->attachCharacter($post, $character);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(0, 'data.attachments');
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonCount(1, 'data.attachments');
    }

    public function test_human_post_does_not_correlate_its_author_to_an_attached_separate_persona(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $separate = Character::factory()->for($author)->create([
            'display_name' => 'Private Persona',
            'is_linked' => false,
        ]);
        $post = Post::factory()->for($author)->approved()->create([
            'audience' => Audience::Everyone,
            'character_id' => null,
        ]);
        $this->attachCharacter($post, $separate);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments')
            ->assertJsonMissing(['label' => 'Private Persona']);

        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.id', $separate->id);
    }

    public function test_separate_persona_post_may_name_that_same_persona_as_an_attachment(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $separate = Character::factory()->for($author)->create([
            'is_linked' => false,
        ]);
        $post = Post::factory()->for($author)->approved()->create([
            'audience' => Audience::Everyone,
            'character_id' => $separate->id,
        ]);
        $this->attachCharacter($post, $separate);

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.id', $separate->id);
    }

    public function test_post_as_restricted_character_shows_the_persona_name_only_never_the_human(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $character = Character::factory()->for($author)->audience(Audience::SpecificPeople)->create();
        $post = Post::factory()->for($author)->approved()->create([
            'audience' => Audience::Everyone,
            'character_id' => $character->id,
        ]);

        // A viewer the persona's audience does not admit still gets the persona
        // *name* (unlinked: no ulid, no avatar) — never the human author, which
        // is exactly what the byline hides.
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.as_character.display_name', $character->display_name)
            ->assertJsonPath('data.as_character.ulid', null)
            ->assertJsonPath('data.as_character.avatar', null)
            ->assertJsonPath('data.author', null);
        $this->actingAs($author)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonPath('data.as_character.id', $character->id)
            ->assertJsonPath('data.as_character.ulid', $character->ulid);
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

    public function test_account_post_rejects_relationship_tier_media_owned_by_a_separate_persona(): void
    {
        $user = User::factory()->approved()->create();
        $separate = Character::factory()->for($user)->create(['is_linked' => false]);

        foreach ([Audience::Followers, Audience::Mutuals] as $audience) {
            $media = Media::factory()->for($user)->approved()->audience($audience)->create([
                'character_id' => $separate->id,
            ]);

            $this->actingAs($user)->postJson('/api/posts', [
                'body' => 'Cross-identity attachment',
                'audience' => Audience::Everyone->value,
                'attachments' => [['type' => 'media', 'id' => $media->id]],
            ])->assertUnprocessable()->assertJsonValidationErrorFor('attachments');
        }

        $this->assertSame(0, Post::query()->count());
    }

    public function test_persona_post_rejects_another_personas_relationship_tier_media(): void
    {
        $user = User::factory()->approved()->create();
        $persona = Character::factory()->for($user)->create(['is_linked' => false]);
        $otherPersona = Character::factory()->for($user)->create(['is_linked' => false]);

        foreach ([Audience::Followers, Audience::Mutuals] as $audience) {
            $media = Media::factory()->for($user)->approved()->audience($audience)->create([
                'character_id' => $otherPersona->id,
            ]);

            $this->actingAs($user)->postJson('/api/posts', [
                'body' => 'Cross-identity attachment',
                'audience' => Audience::Everyone->value,
                'character_id' => $persona->id,
                'attachments' => [['type' => 'media', 'id' => $media->id]],
            ])->assertUnprocessable()->assertJsonValidationErrorFor('attachments');
        }

        $this->assertSame(0, Post::query()->count());
    }

    public function test_pending_media_and_unavailable_stories_cannot_be_manually_attached(): void
    {
        $user = User::factory()->approved()->create();
        $pendingMedia = Media::factory()->for($user)->create();
        $pendingStory = Story::factory()->for($user)->published()->create();
        $draftStory = Story::factory()->for($user)->approved()->create();

        foreach ([
            ['type' => 'media', 'id' => $pendingMedia->id],
            ['type' => 'story', 'id' => $pendingStory->id],
            ['type' => 'story', 'id' => $draftStory->id],
        ] as $attachment) {
            $this->actingAs($user)->postJson('/api/posts', [
                'body' => 'Unavailable attachment',
                'attachments' => [$attachment],
            ])->assertUnprocessable()->assertJsonValidationErrorFor('attachments.0.id');
        }

        $this->assertSame(0, Post::query()->count());
    }

    public function test_manual_post_notification_is_dispatched_only_after_the_aggregate_commits(): void
    {
        Queue::fake();
        $user = User::factory()->approved()->create();
        $media = Media::factory()->for($user)->approved()->create();

        DB::transaction(function () use ($user, $media): void {
            $this->actingAs($user)->postJson('/api/posts', [
                'body' => 'Atomic post',
                'attachments' => [['type' => 'media', 'id' => $media->id]],
            ])->assertCreated();
        });

        Queue::assertPushed(
            NotifyFollowersOfPost::class,
            fn ($job): bool => $job->afterCommit === true,
        );
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

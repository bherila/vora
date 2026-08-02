<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Enums\StoryStatus;
use App\Jobs\NotifyFollowersOfPost;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\User;
use App\Services\Post\CanonicalDiscussionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnnouncementPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_is_announced_only_when_approved_and_copies_its_full_privacy_policy(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $allowed = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();
        $media = Media::factory()->for($owner)->audience(Audience::SpecificPeople)->create([
            'character_id' => $character->id,
            'discoverable' => false,
            'announce_on_approval' => true,
        ]);
        $media->syncAudienceMembers([$allowed->id]);

        $this->assertSame(0, Post::query()->count());

        $media->approve($admin);

        $post = Post::query()->sole();
        $this->assertSame($owner->id, $post->user_id);
        $this->assertSame($character->id, $post->character_id);
        $this->assertSame(Audience::SpecificPeople, $post->audience);
        $this->assertFalse($post->discoverable);
        $this->assertSame([$allowed->id], $post->audienceMembers()->pluck('user_id')->all());
        $this->assertTrue($post->attachments()
            ->where('attachable_type', $media->getMorphClass())
            ->where('attachable_id', $media->id)
            ->exists());
    }

    public function test_unchecked_media_is_not_announced(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->for($owner)->create(['announce_on_approval' => false]);

        $media->approve($admin);

        $this->assertSame(0, Post::query()->count());
    }

    public function test_unannounced_media_lazily_gets_one_hidden_canonical_discussion(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['announce_on_approval' => false]);
        $service = app(CanonicalDiscussionService::class);

        $first = $service->resolveFor($media);
        $second = $service->resolveFor($media->fresh());

        $this->assertTrue($first->is($second));
        $this->assertTrue($first->is_feed_hidden);
        $this->assertFalse($first->is_announcement);
        $this->assertSame($first->id, $media->fresh()->canonical_post_id);
        $this->actingAs($owner)->getJson('/api/feed?scope=mixed')->assertJsonMissing(['ulid' => $first->ulid]);
    }

    public function test_declined_announcement_is_created_only_when_discussion_starts(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['announce_on_approval' => false]);

        $this->assertNull($media->fresh()->canonical_post_id);

        $this->actingAs($owner)->postJson('/api/media/by-ulid/'.$media->ulid.'/discussion', [
            'body' => '',
        ])->assertUnprocessable();

        $this->assertNull($media->fresh()->canonical_post_id);
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, PostComment::query()->count());

        $started = $this->actingAs($owner)->postJson('/api/media/by-ulid/'.$media->ulid.'/discussion', [
            'body' => 'This is worth discussing.',
        ])->assertCreated();

        $this->assertSame($started->json('data.post.id'), $media->fresh()->canonical_post_id);
        $this->assertDatabaseHas('post_comments', [
            'post_id' => $started->json('data.post.id'),
            'user_id' => $owner->id,
            'body' => 'This is worth discussing.',
            'parent_id' => null,
        ]);
        $this->assertSame(1, $started->json('data.post.comment_count'));
    }

    public function test_announced_content_detail_resolves_the_existing_canonical_discussion(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->approve($admin);
        $announcement = Post::query()->sole();

        $resolved = app(CanonicalDiscussionService::class)->resolveFor($story->fresh());

        $this->assertTrue($announcement->is($resolved));
        $this->assertSame($announcement->id, $story->fresh()->canonical_post_id);
    }

    public function test_pending_media_cannot_be_manually_attached_to_suppress_its_later_announcement(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $media = Media::factory()->for($owner)->create(['announce_on_approval' => true]);

        $this->actingAs($owner)->postJson('/api/posts', [
            'body' => 'Share before review',
            'attachments' => [['type' => 'media', 'id' => $media->id]],
        ])->assertUnprocessable();

        $media->approve($admin);

        $post = Post::query()->sole();
        $this->assertTrue($post->is_announcement);
        $this->assertSame($media->id, $post->attachments()->sole()->attachable_id);
    }

    public function test_story_announcement_uses_the_storys_account_scoped_privacy_identity(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $character = Character::factory()->for($owner)->create(['is_linked' => true]);
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->authors()
            ->where('user_id', $owner->id)
            ->update(['character_id' => $character->id]);

        $story->approve($admin);

        $post = Post::query()->sole();
        $this->assertNull($post->character_id);
        $this->assertTrue($post->attachments()
            ->where('attachable_type', $story->getMorphClass())
            ->where('attachable_id', $story->id)
            ->exists());
    }

    public function test_separate_persona_story_is_not_announced_with_its_private_owner_as_byline(): void
    {
        $owner = User::factory()->approved()->create(['display_name' => 'Private Human Identity']);
        $admin = User::factory()->admin()->create();
        $reader = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Public Persona',
            'is_linked' => false,
        ]);
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->authors()->where('user_id', $owner->id)->firstOrFail()->update([
            'character_id' => $persona->id,
        ]);

        $story->approve($admin);

        $this->assertSame(0, Post::query()->count());
        $this->actingAs($reader)
            ->getJson("/api/stories/by-ulid/{$story->ulid}")
            ->assertOk()
            ->assertJsonPath('data.owner.id', null)
            ->assertJsonPath('data.owner.display_name', 'Public Persona')
            ->assertJsonMissing(['display_name' => 'Private Human Identity']);
        $this->actingAs($reader)
            ->getJson('/api/feed')
            ->assertOk()
            ->assertJsonMissing(['display_name' => 'Private Human Identity']);
    }

    public function test_story_announcement_matches_account_follower_and_mutual_privacy_exactly(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $accountFollower = User::factory()->approved()->create();
        $personaFollower = User::factory()->approved()->create();
        $mutual = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create(['is_linked' => true]);

        $this->follow($accountFollower, $owner);
        $this->follow($personaFollower, $owner, $persona);
        $this->follow($mutual, $owner);
        $this->follow($owner, $mutual);

        foreach ([Audience::Followers, Audience::Mutuals] as $audience) {
            $story = Story::factory()->for($owner)->published()->audience($audience)->create([
                'announce_on_approval' => true,
            ]);
            $story->authors()->where('user_id', $owner->id)->update(['character_id' => $persona->id]);
            $story->approve($admin);
            $post = Post::query()->where('is_announcement', true)->latest('id')->firstOrFail();

            foreach ([$accountFollower, $personaFollower, $mutual] as $viewer) {
                $this->assertSame(
                    $story->isViewableBy($viewer),
                    $post->isViewableBy($viewer),
                    "Announcement privacy diverged for {$audience->value}.",
                );
            }

            $this->assertNull($post->character_id);
        }
    }

    public function test_changing_story_owner_identity_to_separate_hides_an_existing_announcement(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $linked = Character::factory()->for($owner)->create(['is_linked' => true]);
        $separate = Character::factory()->for($owner)->create(['is_linked' => false]);
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $ownerAuthor = $story->authors()->where('user_id', $owner->id)->firstOrFail();

        $story->approve($admin);
        $post = Post::query()->sole();
        $this->assertTrue($post->isApprovedContent());

        $ownerAuthor->update(['character_id' => $separate->id]);
        $this->assertTrue($post->refresh()->isPendingReview());

        $ownerAuthor->update(['character_id' => $linked->id]);
        $this->assertTrue($post->refresh()->isApprovedContent());
        $this->assertNull($post->character_id);
    }

    public function test_story_is_not_announced_until_both_published_and_approved_and_never_duplicates(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $story = Story::factory()->for($owner)->create(['announce_on_approval' => true]);

        $story->approve($admin);
        $this->assertSame(0, Post::query()->count());

        $story->update(['status' => StoryStatus::Published, 'published_at' => now()]);
        $this->assertSame(1, Post::query()->count());

        $story->update(['status' => StoryStatus::Draft]);
        $story->update(['status' => StoryStatus::Published]);
        $story->approve($admin);

        $this->assertSame(1, Post::query()->count());
    }

    public function test_later_item_privacy_changes_propagate_to_the_announcement(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $allowed = User::factory()->approved()->create();
        $replacement = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->create(['announce_on_approval' => true]);
        $media->approve($admin);

        $media->update([
            'audience' => Audience::SpecificPeople,
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id, $replacement->id]);
        $media->syncAudienceMembers([$replacement->id]);

        $post = Post::query()->sole()->refresh();
        $this->assertSame(Audience::SpecificPeople, $post->audience);
        $this->assertFalse($post->discoverable);
        $this->assertSame([$replacement->id], $post->audienceMembers()->pluck('user_id')->all());
    }

    public function test_story_announcement_waits_for_final_specific_people_allowlist_before_dispatch(): void
    {
        Queue::fake();
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $allowed = User::factory()->approved()->create();
        $story = Story::factory()->for($owner)->audience(Audience::SpecificPeople)->create([
            'announce_on_approval' => true,
        ]);
        $story->approve($admin);

        DB::transaction(function () use ($story, $allowed): void {
            $story->update(['status' => StoryStatus::Published, 'published_at' => now()]);
            $story->syncAudienceMembers([$allowed->id]);

            $this->assertSame([$allowed->id], Post::query()->sole()->audienceMembers()->pluck('user_id')->all());
        });

        Queue::assertPushed(
            NotifyFollowersOfPost::class,
            fn ($job): bool => $job->afterCommit === true,
        );
        $this->assertSame([$allowed->id], Post::query()->sole()->audienceMembers()->pluck('user_id')->all());
    }

    public function test_announcement_privacy_propagation_rolls_back_as_one_aggregate(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $allowed = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->audience(Audience::SpecificPeople)->create([
            'announce_on_approval' => true,
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id]);
        $media->approve($admin);

        try {
            DB::transaction(function () use ($media): void {
                $media->update(['audience' => Audience::Everyone, 'discoverable' => true]);
                $media->syncAudienceMembers([]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // Expected: both content and announcement privacy must roll back.
        }

        $post = Post::query()->sole()->refresh();
        $this->assertSame(Audience::SpecificPeople, $post->audience);
        $this->assertFalse($post->discoverable);
        $this->assertSame([$allowed->id], $post->audienceMembers()->pluck('user_id')->all());
    }

    public function test_unavailable_content_hides_its_announcement_and_restore_does_not_redispatch(): void
    {
        Queue::fake();
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->approve($admin);

        Queue::assertPushed(NotifyFollowersOfPost::class, 1);
        $post = Post::query()->sole();
        $this->assertTrue($post->isApprovedContent());

        $story->update(['status' => StoryStatus::Draft]);
        $this->assertTrue($post->refresh()->isPendingReview());

        $story->update(['status' => StoryStatus::Published]);
        $this->assertTrue($post->refresh()->isApprovedContent());
        Queue::assertPushed(NotifyFollowersOfPost::class, 1);
    }

    public function test_admin_rejected_announcement_stays_rejected_through_owner_edits_and_restore(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->approve($admin);
        $post = Post::query()->sole();
        $post->reject($admin);

        $this->actingAs($owner)->patchJson("/api/stories/{$story->id}", [
            'title' => 'Revised after announcement review',
        ])->assertOk();
        $this->assertTrue($post->refresh()->isRejected());

        $story->approve($admin);
        $this->assertTrue($post->refresh()->isRejected());
    }

    public function test_manual_post_is_clamped_to_its_most_restrictive_attachment(): void
    {
        $owner = User::factory()->approved()->create();
        $allowed = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->audience(Audience::SpecificPeople)->create([
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id]);

        $this->actingAs($owner)->postJson('/api/posts', [
            'body' => 'A new upload',
            'audience' => Audience::Everyone->value,
            'discoverable' => true,
            'attachments' => [['type' => 'media', 'id' => $media->id]],
        ])->assertCreated();

        $post = Post::query()->sole();
        $this->assertSame(Audience::SpecificPeople, $post->audience);
        $this->assertFalse($post->discoverable);
        $this->assertSame([$allowed->id], $post->audienceMembers()->pluck('user_id')->all());
    }

    public function test_specific_attachment_allowlist_is_intersected_with_the_posts_relationship_tier(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $follower = User::factory()->approved()->create();
        $formerFollower = User::factory()->approved()->create();
        FollowRequest::query()->create([
            'requester_id' => $follower->id,
            'recipient_id' => $owner->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        $media = Media::factory()->for($owner)->approved()->audience(Audience::SpecificPeople)->create();
        $media->syncAudienceMembers([$follower->id, $formerFollower->id]);

        $this->actingAs($owner)->postJson('/api/posts', [
            'body' => 'Followers and selected people only',
            'audience' => Audience::Followers->value,
            'attachments' => [['type' => 'media', 'id' => $media->id]],
        ])->assertCreated();

        $post = Post::query()->sole();
        $this->assertSame(Audience::SpecificPeople, $post->audience);
        $this->assertSame([$follower->id], $post->audienceMembers()->pluck('user_id')->all());
    }

    public function test_post_and_announced_item_have_independent_deletion_lifecycles(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $first = Media::factory()->for($owner)->create(['announce_on_approval' => true]);
        $first->approve($admin);
        $firstPost = Post::query()->sole();

        $firstPost->delete();
        $this->assertNotNull($first->fresh());

        $second = Media::factory()->for($owner)->create(['announce_on_approval' => true]);
        $second->approve($admin);
        $secondPost = Post::query()->whereKeyNot($firstPost->id)->sole();

        $second->delete();
        $this->assertNotNull($secondPost->fresh());
        $this->assertSame(1, $secondPost->attachments()->count());
    }

    private function follow(User $requester, User $recipient, ?Character $persona = null): void
    {
        FollowRequest::query()->create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'recipient_character_id' => $persona?->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }
}

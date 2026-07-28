<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_published_story_is_announced_on_approval_with_its_owner_persona(): void
    {
        $owner = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $character = Character::factory()->for($owner)->create();
        $story = Story::factory()->for($owner)->published()->create(['announce_on_approval' => true]);
        $story->authors()
            ->where('user_id', $owner->id)
            ->update(['character_id' => $character->id]);

        $story->approve($admin);

        $post = Post::query()->sole();
        $this->assertSame($character->id, $post->character_id);
        $this->assertTrue($post->attachments()
            ->where('attachable_type', $story->getMorphClass())
            ->where('attachable_id', $story->id)
            ->exists());
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

    public function test_manual_post_is_clamped_to_its_most_restrictive_attachment(): void
    {
        $owner = User::factory()->approved()->create();
        $allowed = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->audience(Audience::SpecificPeople)->create([
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
        $media = Media::factory()->for($owner)->audience(Audience::SpecificPeople)->create();
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
}

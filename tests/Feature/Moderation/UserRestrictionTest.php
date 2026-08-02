<?php

namespace Tests\Feature\Moderation;

use App\Enums\Audience;
use App\Enums\RestrictionCapability;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\User;
use App\Models\UserRestriction;
use App\Services\Moderation\RestrictionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['id' => 1]);
    }

    #[Test]
    public function admin_can_create_list_and_lift_a_restriction_without_deleting_history(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();

        $created = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/restrictions", [
            'capability' => RestrictionCapability::MediaUpload->value,
            'reason' => 'Repeated unsafe uploads.',
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.capability', 'media.upload')
            ->assertJsonPath('data.reason', 'Repeated unsafe uploads.')
            ->assertJsonPath('data.active', true);

        $restrictionId = (int) $created->json('data.id');
        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}/restrictions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $restrictionId);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}/restrictions/{$restrictionId}")
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('user_restrictions', [
            'id' => $restrictionId,
            'user_id' => $target->id,
            'lifted_by_user_id' => $admin->id,
        ]);
        $this->assertNotNull(UserRestriction::query()->findOrFail($restrictionId)->lifted_at);
        $this->assertFalse(app(RestrictionGate::class)->denies($target, RestrictionCapability::MediaUpload));
    }

    #[Test]
    public function admin_cannot_create_overlapping_active_restrictions_for_the_same_capability(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();
        $endpoint = "/api/admin/users/{$target->id}/restrictions";
        $payload = ['capability' => RestrictionCapability::MediaUpload->value];

        $firstId = (int) $this->actingAs($admin)->postJson($endpoint, $payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)->postJson($endpoint, $payload)
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'An active restriction already exists for this capability.',
            ]);
        $this->actingAs($admin)->postJson($endpoint, [
            'capability' => RestrictionCapability::CommentCreate->value,
        ])->assertCreated();

        $this->actingAs($admin)->deleteJson("{$endpoint}/{$firstId}")->assertOk();
        $afterLiftId = (int) $this->actingAs($admin)->postJson($endpoint, $payload)
            ->assertCreated()
            ->json('data.id');

        UserRestriction::query()->findOrFail($afterLiftId)->forceFill([
            'expires_at' => now()->subSecond(),
        ])->save();

        $this->actingAs($admin)->postJson($endpoint, $payload)->assertCreated();

        $this->assertSame(3, $target->restrictions()->where('capability', $payload['capability'])->count());
        $this->assertSame(1, $target->restrictions()->active()->where('capability', $payload['capability'])->count());
    }

    #[Test]
    public function expiry_is_evaluated_on_read_without_admin_action(): void
    {
        $this->admin();
        $target = User::factory()->approved()->create();
        UserRestriction::factory()->for($target)->capability(RestrictionCapability::MediaView)->create([
            'expires_at' => now()->subSecond(),
        ]);
        UserRestriction::factory()->for($target)->capability(RestrictionCapability::CommentCreate)->create([
            'expires_at' => now()->addMinute(),
        ]);

        $gate = app(RestrictionGate::class);

        $this->assertFalse($gate->denies($target, RestrictionCapability::MediaView));
        $this->assertTrue($gate->denies($target, RestrictionCapability::CommentCreate));
        $this->assertFalse($gate->denies($target, RestrictionCapability::MediaUpload));
    }

    #[Test]
    public function restriction_reason_is_visible_only_to_its_subject_and_admins(): void
    {
        $admin = $this->admin();
        $target = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        UserRestriction::factory()->for($target)->create(['reason' => 'Private sanction reason.']);

        $subjectHtml = $this->actingAs($target)->get('/feed')->assertOk()->getContent();
        $this->assertStringContainsString('Private sanction reason.', (string) $subjectHtml);

        $otherHtml = $this->actingAs($other)->get('/feed')->assertOk()->getContent();
        $this->assertStringNotContainsString('Private sanction reason.', (string) $otherHtml);

        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}/restrictions")
            ->assertJsonPath('data.0.reason', 'Private sanction reason.');
    }

    #[Test]
    public function restricted_user_can_review_and_appeal_the_sanction(): void
    {
        $this->admin();
        $target = User::factory()->approved()->create();
        UserRestriction::factory()->for($target)->create(['reason' => 'Reviewable reason.']);

        $this->actingAs($target)->get('/account/restrictions')
            ->assertOk()
            ->assertSee('Reviewable reason.');
        $this->actingAs($target)->postJson('/api/account/appeal', ['message' => 'Please reconsider.'])
            ->assertOk();

        $this->assertSame('Please reconsider.', $target->fresh()->ban_appeal_message);
        $this->assertNotNull($target->fresh()->ban_appeal_at);
    }

    #[Test]
    public function comment_restriction_blocks_creation_but_not_reading_or_deleting_existing_comments(): void
    {
        $this->admin();
        $postOwner = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($postOwner)->approved()->create();
        $comment = PostComment::factory()->for($post)->for($commenter)->create();
        UserRestriction::factory()->for($commenter)->capability(RestrictionCapability::CommentCreate)->create();

        $this->actingAs($commenter)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment->id);
        $this->actingAs($commenter)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Blocked'])
            ->assertForbidden();
        $this->actingAs($commenter)->deleteJson("/api/posts/{$post->id}/comments/{$comment->id}")
            ->assertOk();
    }

    #[Test]
    public function canonical_discussions_apply_restrictions_only_after_ordinary_content_authorization(): void
    {
        $this->admin();
        $owner = User::factory()->approved()->create();
        $mediaRestricted = User::factory()->approved()->create();
        $commentRestricted = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $privateMedia = Media::factory()->for($owner)->approved()->create([
            'audience' => Audience::SpecificPeople,
        ]);
        $ownMedia = Media::factory()->for($mediaRestricted)->approved()->create();
        $story = Story::factory()->for($owner)->published()->approved()->create();
        UserRestriction::factory()->for($mediaRestricted)->capability(RestrictionCapability::MediaView)->create();
        UserRestriction::factory()->for($commentRestricted)->capability(RestrictionCapability::CommentCreate)->create();

        $this->actingAs($mediaRestricted)
            ->postJson("/api/media/by-ulid/{$media->ulid}/discussion", ['body' => 'Blocked media discussion.'])
            ->assertForbidden();
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, PostComment::query()->count());

        $this->actingAs($mediaRestricted)
            ->postJson("/api/media/by-ulid/{$ownMedia->ulid}/discussion", ['body' => 'Own media discussion.'])
            ->assertCreated();
        $this->actingAs($mediaRestricted)
            ->postJson("/api/stories/by-ulid/{$story->ulid}/discussion", ['body' => 'Stories remain available.'])
            ->assertCreated();
        $this->assertSame(2, Post::query()->count());
        $this->assertSame(2, PostComment::query()->count());

        $this->actingAs($commentRestricted)
            ->postJson("/api/media/by-ulid/{$media->ulid}/discussion", ['body' => 'Commenting blocked.'])
            ->assertForbidden();
        $this->actingAs($commentRestricted)
            ->postJson("/api/stories/by-ulid/{$story->ulid}/discussion", ['body' => 'Commenting blocked.'])
            ->assertForbidden();

        config(['app.debug' => false]);
        $missingForMediaRestriction = $this->actingAs($mediaRestricted)
            ->postJson('/api/media/by-ulid/01ARZ3NDEKTSV4RRFFQ69G5FAV/discussion', ['body' => 'Missing.'])
            ->assertNotFound();
        $hiddenForMediaRestriction = $this->actingAs($mediaRestricted)
            ->postJson("/api/media/by-ulid/{$privateMedia->ulid}/discussion", ['body' => 'Hidden remains neutral.'])
            ->assertNotFound();
        $this->assertSame($missingForMediaRestriction->getContent(), $hiddenForMediaRestriction->getContent());

        $missingForCommentRestriction = $this->actingAs($commentRestricted)
            ->postJson('/api/media/by-ulid/01ARZ3NDEKTSV4RRFFQ69G5FAV/discussion', ['body' => 'Missing.'])
            ->assertNotFound();
        $hiddenForCommentRestriction = $this->actingAs($commentRestricted)
            ->postJson("/api/media/by-ulid/{$privateMedia->ulid}/discussion", ['body' => 'Hidden remains neutral.'])
            ->assertNotFound();
        $this->assertSame($missingForCommentRestriction->getContent(), $hiddenForCommentRestriction->getContent());
        $this->assertSame(2, Post::query()->count());
        $this->assertSame(2, PostComment::query()->count());
    }

    #[Test]
    public function banned_user_can_download_export_even_under_legal_hold_but_cannot_delete_account(): void
    {
        $this->admin();
        $user = User::factory()->banned()->onLegalHold()->create();

        $this->actingAs($user)->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonPath('data.account.id', $user->id);
        $this->actingAs($user)->postJson('/api/account/delete')->assertForbidden();
    }

    #[Test]
    public function banned_user_can_reach_activity_and_delete_their_own_posts_and_comments(): void
    {
        $this->admin();
        $user = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $ownPost = Post::factory()->for($user)->approved()->create(['body' => 'My removable post.']);
        $otherPost = Post::factory()->for($other)->approved()->create();
        $comment = PostComment::factory()->for($otherPost)->for($user)->create(['body' => 'My removable comment.']);
        $user->forceFill(['banned_at' => now()])->save();

        $this->actingAs($user)->get('/me/activity')->assertOk();
        $this->actingAs($user)->getJson('/api/me/activity?type=posts')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $ownPost->ulid]);
        $this->actingAs($user)->getJson('/api/me/activity?type=comments')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $comment->ulid]);

        $this->actingAs($user)
            ->deleteJson("/api/me/activity/comments/{$comment->ulid}")
            ->assertOk();
        $this->actingAs($user)->deleteJson("/api/posts/{$ownPost->id}")->assertOk();

        $this->assertSoftDeleted('post_comments', ['id' => $comment->id]);
        $this->assertSoftDeleted('posts', ['id' => $ownPost->id]);
    }
}

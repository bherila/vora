<?php

namespace Tests\Feature\Posts;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPostModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_moderate_posts(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->create(['body' => 'Review me']);

        $this->actingAs($admin)->getJson('/api/admin/posts')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Review me')
            ->assertJsonPath('data.0.author.id', $owner->id)
            ->assertJsonPath('data.0.moderation_status', 'pending');

        $this->actingAs($admin)->postJson("/api/admin/posts/{$post->id}/moderate", [
            'action' => 'approve',
            'notes' => 'ok',
        ])->assertOk()
            ->assertJsonPath('data.moderation_status', 'approved')
            ->assertJsonPath('data.moderation_notes', 'ok');

        $this->assertSame($admin->id, $post->fresh()->moderated_by_user_id);
    }

    public function test_admin_post_status_filter_and_pagination(): void
    {
        config(['media.page_size' => 2]);
        $admin = User::factory()->admin()->create();
        Post::factory()->count(2)->approved()->create();
        Post::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/posts?status=approved')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.moderation_status', 'approved');
    }

    public function test_rejected_post_is_taken_down_for_other_viewers(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/posts/{$post->id}/moderate", [
            'action' => 'reject',
            'notes' => 'take down',
        ])->assertOk();

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/posts/by-ulid/{$post->ulid}")->assertOk();
    }

    public function test_admin_can_list_and_moderate_comments(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create(['body' => 'Parent post']);
        $comment = PostComment::factory()->for($post)->for($commenter)->create(['body' => 'Bad comment']);

        $this->actingAs($admin)->getJson('/api/admin/post-comments')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Bad comment')
            ->assertJsonPath('data.0.post.id', $post->id)
            ->assertJsonPath('data.0.post.body', 'Parent post')
            ->assertJsonPath('data.0.moderation_status', 'approved');

        $this->actingAs($admin)->postJson("/api/admin/post-comments/{$comment->id}/moderate", [
            'action' => 'reject',
            'notes' => 'spam',
        ])->assertOk()
            ->assertJsonPath('data.moderation_status', 'rejected')
            ->assertJsonPath('data.moderation_notes', 'spam');

        $this->assertSame($admin->id, $comment->fresh()->moderated_by_user_id);
        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_comment_status_filter(): void
    {
        $admin = User::factory()->admin()->create();
        PostComment::factory()->create();
        PostComment::factory()->rejected()->create();

        $this->actingAs($admin)->getJson('/api/admin/post-comments?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.moderation_status', 'rejected');
    }

    public function test_non_admin_cannot_access_post_moderation_endpoints(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->approved()->create();
        $post = Post::factory()->for($user)->create();
        $comment = PostComment::factory()->for($post)->for($user)->create();

        $this->actingAs($user)->getJson('/api/admin/posts')->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/posts/{$post->id}/moderate", ['action' => 'approve'])->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/post-comments')->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/post-comments/{$comment->id}/moderate", ['action' => 'approve'])->assertForbidden();
    }

    public function test_moderation_status_is_not_exposed_to_post_authors_or_comment_readers(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();
        PostComment::factory()->for($post)->for($owner)->create();

        $this->actingAs($owner)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()
            ->assertJsonMissingPath('data.moderation_status');

        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonMissingPath('data.0.moderation_status');
    }
}

<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_comment_and_list_comments(): void
    {
        // Author first so the viewer is not user id 1 (always admin).
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Nice post'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Nice post');

        $comment = PostComment::query()->firstOrFail();
        $this->assertTrue($comment->isApprovedContent(), 'comments publish immediately');

        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_comment_on_a_post_you_cannot_view(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hi'])->assertForbidden();
        $this->assertSame(0, PostComment::query()->count());
    }

    public function test_non_authors_only_see_approved_comments(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();

        PostComment::factory()->for($post)->for($commenter)->create();
        PostComment::factory()->for($post)->for($commenter)->rejected()->create();

        // A bystander sees only the approved one.
        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonCount(1, 'data');
        // The comment author sees both of theirs.
        $this->actingAs($commenter)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_delete_is_limited_to_author_post_owner_and_admin(): void
    {
        // Spacer takes id 1 so the post owner is not auto-admin.
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $url = fn (PostComment $c): string => "/api/posts/{$post->id}/comments/{$c->id}";
        $make = fn (): PostComment => PostComment::factory()->for($post)->for($commenter)->create();

        $this->actingAs($stranger)->deleteJson($url($c = $make()))->assertForbidden();
        $this->actingAs($commenter)->deleteJson($url($c))->assertOk(); // author
        $this->actingAs($owner)->deleteJson($url($make()))->assertOk(); // post owner
        $this->actingAs($admin)->deleteJson($url($make()))->assertOk(); // admin
    }

    public function test_comment_payload_exposes_can_delete_per_viewer(): void
    {
        // Spacer takes id 1 so the post owner is not auto-admin.
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $stranger = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();
        PostComment::factory()->for($post)->for($commenter)->create();

        // The comment's author may delete it.
        $this->actingAs($commenter)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonPath('data.0.can_delete', true);

        // The post owner may delete comments on their post.
        $this->actingAs($owner)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonPath('data.0.can_delete', true);

        // An unrelated viewer may not.
        $this->actingAs($stranger)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonPath('data.0.can_delete', false);
    }

    public function test_a_reply_parent_must_be_a_top_level_comment_on_the_same_post(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $other = Post::factory()->for($author)->approved()->create();

        $foreignParent = PostComment::factory()->for($other)->for($author)->create();
        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'reply', 'parent_id' => $foreignParent->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('parent_id');

        $top = PostComment::factory()->for($post)->for($author)->create();
        $reply = PostComment::factory()->for($post)->for($author)->create(['parent_id' => $top->id]);
        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'nested', 'parent_id' => $reply->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('parent_id');
    }

    public function test_comment_count_counts_only_visible_comments(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        PostComment::factory()->for($post)->for($commenter)->create();
        PostComment::factory()->for($post)->for($commenter)->rejected()->create();

        // A bystander's comment_count matches the visible list (1), not the raw row count.
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertJsonPath('data.comment_count', 1);
    }

    public function test_cannot_reply_to_a_hidden_parent_comment(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        $hiddenParent = PostComment::factory()->for($post)->for($commenter)->rejected()->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'reply', 'parent_id' => $hiddenParent->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('parent_id');
    }

    public function test_comments_from_inactive_accounts_are_hidden_from_others(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $commenter = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        PostComment::factory()->for($post)->for($commenter)->create();

        $commenter->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertJsonPath('data.comment_count', 0);
    }

    public function test_comments_are_retained_when_the_post_is_soft_deleted(): void
    {
        $author = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        PostComment::factory()->for($post)->for($author)->create();

        $post->delete();

        $this->assertSame(1, PostComment::query()->count());
    }

    public function test_comment_count_appears_in_the_post_payload(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->create();
        PostComment::factory()->count(2)->for($post)->for($author)->create();

        $this->actingAs($viewer)->getJson("/api/posts/by-ulid/{$post->ulid}")
            ->assertOk()->assertJsonPath('data.comment_count', 2);
    }
}

<?php

namespace Tests\Feature\Posts;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\FollowRequest;
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

    public function test_owner_comment_on_a_separate_persona_post_is_framed_as_the_persona_for_visitors(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create(['display_name' => 'Private Human']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Public Persona',
            'is_linked' => false,
        ]);
        $post = Post::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
        ]);
        PostComment::factory()->for($post)->for($owner)->create(['body' => 'Persona reply']);

        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $persona->id)
            ->assertJsonPath('data.0.author.display_name', 'Public Persona')
            ->assertJsonMissing(['display_name' => 'Private Human']);

        // Owner management remains account-framed; the owner already knows the
        // relationship and still needs the normal delete controls.
        $this->actingAs($owner)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $owner->id)
            ->assertJsonPath('data.0.author.display_name', 'Private Human');
    }

    public function test_owner_comment_on_a_soft_deleted_persona_post_never_falls_back_to_the_human(): void
    {
        User::factory()->create(); // spacer so nobody under test is the admin (id 1)
        $owner = User::factory()->approved()->create(['display_name' => 'Private Human']);
        $viewer = User::factory()->approved()->create();
        $persona = Character::factory()->for($owner)->create([
            'display_name' => 'Public Persona',
            'is_linked' => false,
        ]);
        $post = Post::factory()->for($owner)->approved()->create([
            'character_id' => $persona->id,
        ]);
        PostComment::factory()->for($post)->for($owner)->create(['body' => 'Persona reply']);

        $persona->delete();

        $response = $this->actingAs($viewer)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author', null);
        $this->assertStringNotContainsString('Private Human', $response->getContent());

        $this->actingAs($owner)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $owner->id);
    }

    public function test_linked_persona_and_unrelated_comments_keep_normal_human_attribution(): void
    {
        $owner = User::factory()->approved()->create(['display_name' => 'Visible Owner']);
        $commenter = User::factory()->approved()->create(['display_name' => 'Other Commenter']);
        $viewer = User::factory()->approved()->create();
        $linked = Character::factory()->for($owner)->create([
            'is_linked' => true,
        ]);
        $post = Post::factory()->for($owner)->approved()->create([
            'character_id' => $linked->id,
        ]);
        PostComment::factory()->for($post)->for($owner)->create(['body' => 'Owner reply']);
        PostComment::factory()->for($post)->for($commenter)->create(['body' => 'Other reply']);

        $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.author.id', $owner->id)
            ->assertJsonPath('data.0.author.display_name', 'Visible Owner')
            ->assertJsonPath('data.1.author.id', $commenter->id)
            ->assertJsonPath('data.1.author.display_name', 'Other Commenter');
    }

    public function test_cannot_comment_on_a_post_you_cannot_view(): void
    {
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hi'])->assertNotFound();
        $this->assertSame(0, PostComment::query()->count());
    }

    public function test_invalid_comment_on_a_hidden_post_still_404s_not_422(): void
    {
        // Authorization runs before validation: an invalid (empty) body posted to a
        // post the viewer cannot see must 404, not 422 — a 422 would reveal the post
        // exists, distinguishing it from a missing id (which the route binding 404s).
        $author = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($author)->approved()->audience(Audience::Followers)->create();

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", [])->assertNotFound();
        $this->actingAs($viewer)->postJson('/api/posts/999999/comments', [])->assertNotFound();
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

    public function test_delete_is_limited_to_author_and_post_owner_not_admin(): void
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

        $this->actingAs($stranger)->deleteJson($url($c = $make()))->assertNotFound();
        $this->actingAs($commenter)->deleteJson($url($c))->assertOk(); // author
        $this->actingAs($owner)->deleteJson($url($make()))->assertOk(); // post owner
        $this->actingAs($admin)->deleteJson($url($make()))->assertNotFound(); // admin uses moderation instead
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

    public function test_comment_index_uses_viewer_scoped_etags_and_private_conditional_responses(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $initial = $this->actingAs($viewer)->getJson("/api/posts/{$post->id}/comments")->assertOk();
        $etag = $initial->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertSame('no-cache, private', $initial->headers->get('Cache-Control'));

        $unchanged = $this->actingAs($viewer)
            ->withHeader('If-None-Match', $etag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertStatus(304);
        $this->assertSame($etag, $unchanged->headers->get('ETag'));
        $this->assertSame('no-cache, private', $unchanged->headers->get('Cache-Control'));
        $this->assertSame('', $unchanged->getContent());

        $this->actingAs($viewer)->postJson("/api/posts/{$post->id}/comments", ['body' => 'Revision change'])
            ->assertCreated();
        $changed = $this->actingAs($viewer)
            ->withHeader('If-None-Match', $etag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));

        $ownerEtag = $this->actingAs($owner)->getJson("/api/posts/{$post->id}/comments")->headers->get('ETag');
        $this->assertNotSame($changed->headers->get('ETag'), $ownerEtag, 'ETags are scoped to the viewer');
        $this->assertSame("/api/posts/{$post->id}/comments", route('posts.comments.index', ['post' => $post], false));
        $route = app('router')->getRoutes()->getByName('posts.comments.index');
        $this->assertNotNull($route);
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    public function test_lost_parent_access_returns_forbidden_before_a_matching_etag(): void
    {
        $owner = User::factory()->approved()->create();
        $viewer = User::factory()->approved()->create();
        $post = Post::factory()->for($owner)->approved()->audience(Audience::Followers)->create();
        $follow = FollowRequest::query()->create([
            'requester_id' => $viewer->id,
            'recipient_id' => $owner->id,
            'status' => FollowRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        $etag = $this->actingAs($viewer)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertOk()
            ->headers->get('ETag');

        $follow->delete();

        $this->actingAs($viewer)
            ->withHeader('If-None-Match', $etag)
            ->getJson("/api/posts/{$post->id}/comments")
            ->assertForbidden();
    }

    public function test_comment_revision_changes_for_owner_remove_author_delete_and_admin_moderation(): void
    {
        User::factory()->create(); // keep the accounts under test non-admin
        $owner = User::factory()->approved()->create();
        $author = User::factory()->approved()->create();
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->for($owner)->approved()->create();

        $ownerRemoved = PostComment::factory()->for($post)->for($author)->create();
        $afterCreate = $post->fresh()->comment_revision;
        $this->actingAs($owner)
            ->deleteJson("/api/posts/{$post->id}/comments/{$ownerRemoved->id}")
            ->assertOk();
        $this->assertGreaterThan($afterCreate, $afterOwnerRemove = $post->fresh()->comment_revision);

        $authorDeleted = PostComment::factory()->for($post)->for($author)->create();
        $afterSecondCreate = $post->fresh()->comment_revision;
        $this->actingAs($author)
            ->deleteJson('/api/me/activity/comments/'.$authorDeleted->ulid)
            ->assertOk();
        $this->assertGreaterThan($afterSecondCreate, $afterAuthorDelete = $post->fresh()->comment_revision);

        $moderated = PostComment::factory()->for($post)->for($author)->create();
        $afterThirdCreate = $post->fresh()->comment_revision;
        $this->actingAs($admin)->postJson("/api/admin/post-comments/{$moderated->id}/moderate", [
            'action' => 'reject',
        ])->assertOk();
        $this->assertGreaterThan($afterThirdCreate, $post->fresh()->comment_revision);
        $this->assertGreaterThan($afterOwnerRemove, $afterAuthorDelete);
    }
}

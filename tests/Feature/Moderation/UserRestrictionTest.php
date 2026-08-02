<?php

namespace Tests\Feature\Moderation;

use App\Enums\RestrictionCapability;
use App\Models\Post;
use App\Models\PostComment;
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
        $this->markTestSkipped('Requires the Your activity routes and author-scoped comment deletion from #193.');
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\ReportStatus;
use App\Models\Media;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_report_count_shows_on_the_admin_nav(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        Report::factory()->targeting($media)->create([
            'reporter_user_id' => User::factory()->approved()->create()->id,
        ]);

        $this->actingAs($admin)->get('/me')->assertOk()->assertSee('Abuse reports (1)', false);
    }

    public function test_non_admin_cannot_list_reports(): void
    {
        // User id 1 is always treated as admin, so burn it on a filler first.
        User::factory()->admin()->create();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->getJson('/api/admin/reports')->assertForbidden();
    }

    public function test_admin_lists_open_reports_with_item_and_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Owner One']);
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create(['title' => 'Bad pic']);
        Report::factory()->targeting($media)->create(['reporter_user_id' => $reporter->id]);

        $this->actingAs($admin)->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.reportable.type', 'media')
            ->assertJsonPath('data.0.reportable.label', 'Bad pic')
            ->assertJsonPath('data.0.reportable.owner.id', $owner->id)
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_dismiss_marks_report_dismissed_without_touching_item(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $report = Report::factory()->targeting($media)->create(['reporter_user_id' => $reporter->id]);

        $this->actingAs($admin)->postJson("/api/admin/reports/{$report->id}/act", ['action' => 'dismiss'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');

        $this->assertNotSoftDeleted('media', ['id' => $media->id]);
        $this->assertFalse($owner->fresh()->isBanned());
    }

    public function test_delete_item_removes_content_and_resolves_sibling_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $first = Report::factory()->targeting($media)->create(['reporter_user_id' => User::factory()->approved()->create()->id]);
        $sibling = Report::factory()->targeting($media)->create(['reporter_user_id' => User::factory()->approved()->create()->id]);

        $this->actingAs($admin)->postJson("/api/admin/reports/{$first->id}/act", ['action' => 'delete_item'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSoftDeleted('media', ['id' => $media->id]);
        // The other open report against the same item is cleared too.
        $this->assertSame(ReportStatus::Resolved, $sibling->fresh()->status);
    }

    public function test_suspend_owner_bans_account_and_removes_item(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $report = Report::factory()->targeting($media)->create(['reporter_user_id' => User::factory()->approved()->create()->id]);

        $this->actingAs($admin)->postJson("/api/admin/reports/{$report->id}/act", [
            'action' => 'suspend_owner',
            'notes' => 'Repeated abuse',
        ])->assertOk();

        $owner->refresh();
        $this->assertTrue($owner->isBanned());
        $this->assertTrue((bool) $owner->ban_hides_content);
        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }

    public function test_legal_hold_owner_sets_hold_and_removes_item(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $report = Report::factory()->targeting($media)->create(['reporter_user_id' => User::factory()->approved()->create()->id]);

        $this->actingAs($admin)->postJson("/api/admin/reports/{$report->id}/act", ['action' => 'legal_hold_owner'])
            ->assertOk();

        $this->assertTrue($owner->fresh()->isOnLegalHold());
        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }

    public function test_cannot_take_account_action_against_primary_admin(): void
    {
        // The primary admin is user id 1 — the first row created here.
        $primary = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $this->assertSame(1, $primary->id);
        $media = Media::factory()->for($primary)->approved()->create();
        $report = Report::factory()->targeting($media)->create(['reporter_user_id' => User::factory()->approved()->create()->id]);

        $this->actingAs($admin)->postJson("/api/admin/reports/{$report->id}/act", ['action' => 'suspend_owner'])
            ->assertStatus(422);

        $this->assertFalse($primary->fresh()->isBanned());
    }
}

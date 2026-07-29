<?php

namespace Tests\Feature\Reports;

use App\Enums\Audience;
use App\Enums\ReportStatus;
use App\Models\Character;
use App\Models\Media;
use App\Models\Report;
use App\Models\User;
use App\Notifications\AbuseReportFiled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_report_visible_media(): void
    {
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'media',
            'id' => $media->id,
            'reason' => 'harassment',
            'details' => 'This is abusive.',
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('reports', [
            'reporter_user_id' => $reporter->id,
            'reportable_type' => $media->getMorphClass(),
            'reportable_id' => $media->id,
            'reason' => 'harassment',
            'status' => ReportStatus::Open->value,
        ]);
    }

    public function test_cannot_report_own_content(): void
    {
        $owner = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $this->actingAs($owner)->postJson('/api/reports', [
            'type' => 'media',
            'id' => $media->id,
            'reason' => 'spam',
        ])->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_cannot_report_media_the_viewer_cannot_see(): void
    {
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        // Unlisted + pending is not visible to a stranger.
        $media = Media::factory()->for($owner)->unlisted()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'media',
            'id' => $media->id,
            'reason' => 'spam',
        ])->assertForbidden();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_duplicate_open_report_is_a_no_op(): void
    {
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $payload = ['type' => 'media', 'id' => $media->id, 'reason' => 'spam'];

        $this->actingAs($reporter)->postJson('/api/reports', $payload)->assertCreated();
        $this->actingAs($reporter)->postJson('/api/reports', $payload)->assertCreated();

        $this->assertSame(1, Report::query()->count());
    }

    public function test_invalid_type_is_rejected(): void
    {
        $reporter = User::factory()->approved()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'unknown',
            'id' => 1,
            'reason' => 'spam',
        ])->assertStatus(422);
    }

    public function test_viewer_can_report_a_visible_user_profile(): void
    {
        User::factory()->create();
        $target = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'user',
            'id' => $target->id,
            'reason' => 'harassment',
        ])->assertCreated();

        $this->assertDatabaseHas('reports', [
            'reporter_user_id' => $reporter->id,
            'reportable_type' => $target->getMorphClass(),
            'reportable_id' => $target->id,
        ]);
    }

    public function test_viewer_cannot_report_a_user_profile_hidden_from_them(): void
    {
        User::factory()->create();
        $target = User::factory()->approved()->create([
            'profile_audience' => Audience::SpecificPeople,
        ]);
        $reporter = User::factory()->approved()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'user',
            'id' => $target->id,
            'reason' => 'spam',
        ])->assertForbidden();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_allowlisted_viewer_can_report_a_specific_people_character(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $character = Character::factory()
            ->for($owner)
            ->audience(Audience::SpecificPeople)
            ->create();
        $character->syncAudienceMembers([$reporter->id]);

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'character',
            'id' => $character->id,
            'reason' => 'harassment',
        ])->assertCreated();

        $this->assertDatabaseHas('reports', [
            'reporter_user_id' => $reporter->id,
            'reportable_type' => $character->getMorphClass(),
            'reportable_id' => $character->id,
        ]);
    }

    public function test_reporting_a_separate_persona_returns_only_the_generic_visitor_contract(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Hidden Owner']);
        $reporter = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create([
            'display_name' => 'Separate Persona',
            'is_linked' => false,
        ]);

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'character',
            'id' => $character->id,
            'reason' => 'harassment',
        ])->assertCreated()->assertExactJson([
            'success' => true,
            'message' => 'Thanks — our team will review this report.',
        ]);

        $report = Report::query()->sole();
        $this->assertSame($character->getMorphClass(), $report->reportable_type);
        $this->assertSame($character->id, $report->reportable_id);
        $this->assertSame($owner->id, $report->reportable->user_id);
    }

    public function test_viewer_cannot_report_a_character_hidden_from_them(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $character = Character::factory()
            ->for($owner)
            ->audience(Audience::SpecificPeople)
            ->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'character',
            'id' => $character->id,
            'reason' => 'spam',
        ])->assertForbidden();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_viewer_cannot_report_their_own_profile_or_character(): void
    {
        User::factory()->create();
        $owner = User::factory()->approved()->create();
        $character = Character::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson('/api/reports', [
            'type' => 'user',
            'id' => $owner->id,
            'reason' => 'spam',
        ])->assertStatus(422);

        $this->actingAs($owner)->postJson('/api/reports', [
            'type' => 'character',
            'id' => $character->id,
            'reason' => 'spam',
        ])->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_filing_a_report_notifies_admins_only(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();

        $this->actingAs($reporter)->postJson('/api/reports', [
            'type' => 'media', 'id' => $media->id, 'reason' => 'spam',
        ])->assertCreated();

        Notification::assertSentTo($admin, AbuseReportFiled::class);
        Notification::assertNotSentTo($reporter, AbuseReportFiled::class);
        Notification::assertNotSentTo($owner, AbuseReportFiled::class);
    }

    public function test_duplicate_report_does_not_re_notify(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $reporter = User::factory()->approved()->create();
        $media = Media::factory()->for($owner)->approved()->create();
        $payload = ['type' => 'media', 'id' => $media->id, 'reason' => 'spam'];

        $this->actingAs($reporter)->postJson('/api/reports', $payload)->assertCreated();
        $this->actingAs($reporter)->postJson('/api/reports', $payload)->assertCreated();

        Notification::assertSentToTimes($admin, AbuseReportFiled::class, 1);
    }
}

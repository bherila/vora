<?php

namespace Tests\Feature\Reports;

use App\Enums\ReportStatus;
use App\Models\Media;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'type' => 'user',
            'id' => 1,
            'reason' => 'spam',
        ])->assertStatus(422);
    }
}

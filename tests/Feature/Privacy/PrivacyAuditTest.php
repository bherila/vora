<?php

namespace Tests\Feature\Privacy;

use App\Enums\Audience;
use App\Enums\StoryType;
use App\Models\PrivacyAuditLog;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The privacy audit trail (SOC2-style attribution). Driven through the Story API
 * because it has no storage dependency; the auditing is shared with Media.
 */
class PrivacyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_content_records_its_initial_privacy_policy(): void
    {
        $author = User::factory()->approved()->create();
        $granted = User::factory()->approved()->create();

        $response = $this->actingAs($author)->postJson('/api/stories', [
            'title' => 'Secret',
            'type' => StoryType::LongForm->value,
            'audience' => Audience::SpecificPeople->value,
            'audience_user_ids' => [$granted->id],
        ]);

        $response->assertCreated();
        $story = Story::query()->firstOrFail();

        $this->assertTrue($story->audienceMembers()->where('user_id', $granted->id)->exists());

        $log = PrivacyAuditLog::query()->firstOrFail();
        $this->assertSame($story->getMorphClass(), $log->privacyable_type);
        $this->assertSame($story->id, $log->privacyable_id);
        $this->assertSame($author->id, $log->user_id);
        $this->assertSame(PrivacyAuditLog::ACTION_CREATED, $log->action);
        $this->assertNull($log->old_audience);
        $this->assertSame(Audience::SpecificPeople->value, $log->new_audience);
        $this->assertSame([$granted->id], $log->added_user_ids);
    }

    public function test_changing_the_audience_is_audited_with_old_and_new(): void
    {
        $author = User::factory()->approved()->create();
        $story = Story::factory()->for($author)->create(['audience' => Audience::Everyone]);

        $this->actingAs($author)
            ->patchJson("/api/stories/{$story->id}", ['audience' => Audience::Followers->value])
            ->assertOk();

        $log = PrivacyAuditLog::query()->where('action', PrivacyAuditLog::ACTION_UPDATED)->firstOrFail();
        $this->assertSame(Audience::Everyone->value, $log->old_audience);
        $this->assertSame(Audience::Followers->value, $log->new_audience);
    }

    public function test_a_no_op_update_writes_no_audit_record(): void
    {
        $author = User::factory()->approved()->create();
        $story = Story::factory()->for($author)->create(['audience' => Audience::Everyone]);
        PrivacyAuditLog::query()->delete(); // ignore the creation log from the factory path

        $this->actingAs($author)
            ->patchJson("/api/stories/{$story->id}", ['title' => 'Renamed only'])
            ->assertOk();

        $this->assertSame(0, PrivacyAuditLog::query()->count());
    }

    public function test_erasing_the_actor_nulls_the_link_but_retains_the_record(): void
    {
        $author = User::factory()->approved()->create();
        $story = Story::factory()->for($author)->create(['audience' => Audience::Everyone]);

        $this->actingAs($author)
            ->patchJson("/api/stories/{$story->id}", ['audience' => Audience::Mutuals->value])
            ->assertOk();

        $author->forceDelete();

        $log = PrivacyAuditLog::query()->where('action', PrivacyAuditLog::ACTION_UPDATED)->firstOrFail();
        $this->assertNull($log->user_id, 'erasure drops the PII linkage');
        $this->assertSame(Audience::Mutuals->value, $log->new_audience, 'the compliance record is retained');
    }
}

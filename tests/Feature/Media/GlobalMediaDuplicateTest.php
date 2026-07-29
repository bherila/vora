<?php

namespace Tests\Feature\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\Report;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\GlobalMediaDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GlobalMediaDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://storage.example/view');
            $mock->shouldReceive('getSignedDownloadUrl')->andReturn('https://storage.example/download');
            $mock->shouldReceive('get')->andReturn(null);
        });
    }

    public function test_cross_account_clusters_use_the_tighter_global_threshold_and_sort_by_size(): void
    {
        config(['media.pdq_global_threshold' => 15]);
        $firstOwner = User::factory()->approved()->create();
        $secondOwner = User::factory()->approved()->create();
        $thirdOwner = User::factory()->approved()->create();
        $fourthOwner = User::factory()->approved()->create();

        $first = Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('0', 64)]);
        $second = Media::factory()->for($secondOwner)->create(['pdq_hash' => str_repeat('0', 63).'1']);
        $third = Media::factory()->for($thirdOwner)->create(['pdq_hash' => str_repeat('0', 63).'3']);

        $smallA = Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('a', 64)]);
        $smallB = Media::factory()->for($fourthOwner)->create(['pdq_hash' => str_repeat('a', 63).'b']);

        // Same-owner-only, incomplete, deleted, video, and 20-bit-away pairs do
        // not produce global clusters.
        Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('f', 64)]);
        Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('f', 64)]);
        Media::factory()->for($fourthOwner)->pendingUpload()->create(['pdq_hash' => str_repeat('0', 64)]);
        Media::factory()->for($fourthOwner)->video()->create(['pdq_hash' => str_repeat('0', 64)]);
        Media::factory()->for($fourthOwner)->create(['pdq_hash' => str_repeat('0', 59).'fffff']);
        $deleted = Media::factory()->for($fourthOwner)->create(['pdq_hash' => str_repeat('0', 64)]);
        $deleted->delete();

        $clusters = app(GlobalMediaDuplicateService::class)->clusters();

        $this->assertCount(2, $clusters);
        $this->assertSame(3, $clusters[0]['media_count']);
        $this->assertSame(3, $clusters[0]['account_count']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            collect($clusters[0]['media'])->pluck('id')->all(),
        );
        $this->assertSame(2, $clusters[1]['media_count']);
        $this->assertEqualsCanonicalizing(
            [$smallA->id, $smallB->id],
            collect($clusters[1]['media'])->pluck('id')->all(),
        );
    }

    public function test_admin_media_response_links_direct_cross_account_matches(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $firstOwner = User::factory()->approved()->create(['display_name' => 'First account']);
        $secondOwner = User::factory()->approved()->create(['display_name' => 'Second account']);
        $first = Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('0', 64)]);
        $second = Media::factory()->for($secondOwner)->create(['pdq_hash' => str_repeat('0', 63).'1']);

        $response = $this->actingAs($admin)->getJson('/api/admin/media')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $first->id);

        $this->assertSame(1, $row['cross_account_duplicates']['other_account_count']);
        $this->assertSame(1, $row['cross_account_duplicates']['match_count']);
        $this->assertSame($second->id, $row['cross_account_duplicates']['matches'][0]['media_id']);
        $this->assertSame("/m/{$second->ulid}", $row['cross_account_duplicates']['matches'][0]['media_href']);
        $this->assertSame("/admin/users#user-{$secondOwner->id}", $row['cross_account_duplicates']['matches'][0]['account_href']);
        $this->assertSame(1, $row['cross_account_duplicates']['matches'][0]['distance']);
    }

    public function test_global_duplicate_signals_are_absent_from_owner_responses(): void
    {
        $this->fakeStorage();
        User::factory()->admin()->create();
        $owner = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        Media::factory()->for($other)->create(['pdq_hash' => str_repeat('0', 64)]);
        Media::factory()->for($owner)->create(['pdq_hash' => str_repeat('0', 64)]);

        $this->actingAs($owner)->getJson('/api/media')
            ->assertOk()
            ->assertJsonMissingPath('data.0.cross_account_duplicates')
            ->assertJsonMissingPath('data.0.pdq_hash');
    }

    public function test_duplicate_clusters_are_admin_only_and_expose_review_links(): void
    {
        $this->fakeStorage();
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->approved()->create();
        $owner = User::factory()->approved()->create(['display_name' => 'Cluster account']);
        $first = Media::factory()->for($viewer)->create(['pdq_hash' => str_repeat('0', 64)]);
        $second = Media::factory()->for($owner)->create(['pdq_hash' => str_repeat('0', 64)]);

        $this->actingAs($viewer)->getJson('/api/admin/media-duplicates')->assertForbidden();
        $this->get('/admin/media-duplicates')->assertForbidden();

        $this->actingAs($admin)->get('/admin/media-duplicates')->assertOk();
        $this->getJson('/api/admin/media-duplicates?sort=size_desc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media_count', 2)
            ->assertJsonPath('data.0.account_count', 2)
            ->assertJsonPath('data.0.media.0.type', MediaType::Photo->value)
            ->assertJsonPath('data.0.media.0.url', 'https://storage.example/view');

        $ids = collect($this->getJson('/api/admin/media-duplicates')->json('data.0.media'))->pluck('id');
        $this->assertTrue($ids->contains($first->id));
        $this->assertTrue($ids->contains($second->id));
    }

    public function test_admin_can_queue_a_cross_account_match_for_existing_abuse_review_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $firstOwner = User::factory()->approved()->create();
        $secondOwner = User::factory()->approved()->create();
        $first = Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('0', 64)]);
        Media::factory()->for($secondOwner)->create(['pdq_hash' => str_repeat('0', 64)]);
        $unmatched = Media::factory()->for($firstOwner)->create(['pdq_hash' => str_repeat('f', 64)]);

        $this->actingAs($admin)
            ->postJson("/api/admin/media/{$first->id}/duplicate-review")
            ->assertCreated()
            ->assertJsonPath('success', true);

        $report = Report::query()->sole();
        $this->assertSame($admin->id, $report->reporter_user_id);
        $this->assertSame($first->getMorphClass(), $report->reportable_type);
        $this->assertSame($first->id, $report->reportable_id);
        $this->assertSame('spam', $report->reason->value);
        $this->assertSame('open', $report->status->value);

        $this->postJson("/api/admin/media/{$first->id}/duplicate-review")
            ->assertOk();
        $this->assertSame(1, Report::query()->count());

        $this->postJson("/api/admin/media/{$unmatched->id}/duplicate-review")
            ->assertUnprocessable();
    }
}

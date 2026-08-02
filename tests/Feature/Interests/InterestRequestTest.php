<?php

namespace Tests\Feature\Interests;

use App\Models\Interest;
use App\Models\InterestRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InterestRequestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // First created user is reserved as primary admin.
        User::factory()->admin()->create();

        return User::factory()->admin()->create();
    }

    #[Test]
    public function approved_users_can_submit_interest_requests(): void
    {
        $user = User::factory()->approved()->create();
        $parent = Interest::query()->create([
            'name' => 'Parent Interest',
        ]);

        $this->actingAs($user)->postJson('/api/interests/request', [
            'name' => 'Requested Interest',
            'description' => 'Requested description',
            'parent_interest_id' => $parent->id,
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('interest_requests', [
            'name' => 'Requested Interest',
            'description' => 'Requested description',
            'parent_interest_id' => $parent->id,
            'user_id' => $user->id,
            'status' => InterestRequest::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function pending_users_cannot_submit_interest_requests(): void
    {
        $user = User::factory()->pendingApproval()->create();

        $this->actingAs($user)->postJson('/api/interests/request', [
            'name' => 'Denied Request',
        ])->assertForbidden();
    }

    #[Test]
    public function admin_can_approve_interest_request(): void
    {
        $admin = $this->admin();
        $requestingUser = User::factory()->approved()->create();
        $parent = Interest::query()->create(['name' => 'Parent']);

        $interestRequest = InterestRequest::query()->create([
            'user_id' => $requestingUser->id,
            'name' => 'Review me',
            'description' => 'Needs approval',
            'parent_interest_id' => $parent->id,
            'status' => InterestRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)->postJson("/api/admin/interest-requests/{$interestRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('interests', [
            'name' => 'Review me',
            'description' => 'Needs approval',
            'parent_interest_id' => $parent->id,
        ]);

        $interestRequest->refresh();
        $this->assertSame(InterestRequest::STATUS_APPROVED, $interestRequest->status);
        $this->assertSame($admin->id, $interestRequest->reviewed_by_user_id);
        $this->assertNotNull($interestRequest->reviewed_at);
    }

    #[Test]
    public function admin_can_reject_interest_request(): void
    {
        $admin = $this->admin();
        $requestingUser = User::factory()->approved()->create();

        $interestRequest = InterestRequest::query()->create([
            'user_id' => $requestingUser->id,
            'name' => 'Reject Me',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/interest-requests/{$interestRequest->id}/reject")
            ->assertOk()
            ->assertJsonPath('success', true);

        $interestRequest->refresh();
        $this->assertSame(InterestRequest::STATUS_REJECTED, $interestRequest->status);
        $this->assertSame($admin->id, $interestRequest->reviewed_by_user_id);
    }

    #[Test]
    public function admin_can_edit_pending_interest_request(): void
    {
        $admin = $this->admin();
        $requestingUser = User::factory()->approved()->create();

        $interestRequest = InterestRequest::query()->create([
            'user_id' => $requestingUser->id,
            'name' => 'Review me',
            'description' => 'Needs attention',
        ]);

        $this->actingAs($admin)->putJson("/api/admin/interest-requests/{$interestRequest->id}", [
            'name' => 'Updated request',
            'description' => 'Needs a better description',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('interest_requests', [
            'id' => $interestRequest->id,
            'name' => 'Updated request',
            'description' => 'Needs a better description',
        ]);
    }

    #[Test]
    public function admin_can_delete_pending_interest_request(): void
    {
        $admin = $this->admin();
        $requestingUser = User::factory()->approved()->create();

        $interestRequest = InterestRequest::query()->create([
            'user_id' => $requestingUser->id,
            'name' => 'Review me',
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/interest-requests/{$interestRequest->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('interest_requests', ['id' => $interestRequest->id]);
    }

    #[Test]
    public function colliding_max_length_slugs_are_bounded_for_strict_sql_engines(): void
    {
        $admin = $this->admin();
        $base = str_repeat('a', 254);

        $this->actingAs($admin)->postJson('/api/admin/interests', [
            'name' => $base.'!',
        ])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/interests', [
            'name' => $base.'?',
        ])->assertOk();

        $slugs = Interest::query()->whereIn('name', [$base.'!', $base.'?'])->orderBy('id')->pluck('slug')->all();
        $this->assertSame($base, $slugs[0]);
        $this->assertSame(str_repeat('a', 253).'-2', $slugs[1]);
        $this->assertSame([254, 255], array_map('strlen', $slugs));
    }
}

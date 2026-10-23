<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventOrganizerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['instance.modules.events' => true]);
        $this->admin = User::factory()->admin()->create(['id' => 100]);
    }

    public function test_organizer_pages_and_apis_require_an_admin_and_the_event_module(): void
    {
        $attendee = User::factory()->approved()->create();
        $this->actingAs($attendee)->get('/events/organizer')->assertForbidden();
        $this->getJson('/api/admin/events/attendees')->assertForbidden();
        $this->putJson('/api/admin/events/attendees/'.$attendee->id.'/access', ['event_ulids' => []])->assertForbidden();
        $this->actingAs($this->admin)->get('/events/organizer')->assertOk();
        config(['instance.modules.events' => false]);
        $this->get('/events/organizer')->assertNotFound();
        $this->getJson('/api/admin/events/attendees')->assertNotFound();
    }

    public function test_organizer_search_and_filters_return_only_needed_attendee_details(): void
    {
        $pending = User::factory()->pendingApproval()->create(['display_name' => 'Guest awaiting approval', 'email' => 'guest@example.test']);
        $approved = User::factory()->approved()->create(['display_name' => 'Approved guest']);
        $event = Event::create(['name' => 'Selected gathering', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $event->members()->attach($approved);
        $this->actingAs($this->admin)->getJson('/api/admin/events/attendees?search=guest%40example.test&status=pending')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.can_manage_access', false)
            ->assertJsonPath('data.0.event_uploads_auto_approved', false)
            ->assertJsonMissingPath('data.0.birth_date')->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/admin/events/attendees?search=Approved&status=approved')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_ulids', [$event->ulid])
            ->assertJsonPath('data.0.can_manage_access', true);
    }

    public function test_ineligible_and_unverified_accounts_cannot_be_presented_as_ready_for_approval(): void
    {
        User::factory()->unverified()->pendingApproval()->create(['display_name' => 'Not verified']);
        User::factory()->pendingApproval()->create(['display_name' => 'Too young', 'birth_date' => today()->subYears(5)]);
        $unverified = $this->actingAs($this->admin)->getJson('/api/admin/events/attendees?search=Not%20verified')->assertOk();
        $unverified->assertJsonPath('data.0.blocked_reason', 'email_verification')->assertJsonPath('data.0.can_approve', false);
        $this->getJson('/api/admin/events/attendees?search=Too%20young')->assertOk()
            ->assertJsonPath('data.0.blocked_reason', 'minimum_age')->assertJsonPath('data.0.can_approve', false);
    }

    public function test_membership_update_changes_only_the_selected_attendee_and_preserves_everyone_semantics(): void
    {
        $attendee = User::factory()->approved()->create();
        $other = User::factory()->approved()->create();
        $shower = Event::create(['name' => 'Shower', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $rehearsal = Event::create(['name' => 'Rehearsal', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $everyone = Event::create(['name' => 'Reception', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        $shower->members()->attach([$attendee->id, $other->id]);
        $this->actingAs($this->admin)->putJson('/api/admin/events/attendees/'.$attendee->id.'/access', ['event_ulids' => [$rehearsal->ulid]])->assertOk();
        $this->assertFalse($shower->members()->whereKey($attendee->id)->exists());
        $this->assertTrue($shower->members()->whereKey($other->id)->exists());
        $this->assertTrue($rehearsal->members()->whereKey($attendee->id)->exists());
        $this->assertSame(0, $everyone->members()->count());
        $this->actingAs($attendee)->getJson('/api/events')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissing(['ulid' => $shower->ulid]);
        $this->actingAs($this->admin)->putJson('/api/admin/events/attendees/'.$attendee->id.'/access', ['event_ulids' => []])->assertOk();
        $this->assertSame(0, $rehearsal->members()->count());
    }

    public function test_pending_attendees_and_everyone_event_assignments_are_rejected_without_partial_changes(): void
    {
        $pending = User::factory()->pendingApproval()->create();
        $approved = User::factory()->approved()->create();
        $specific = Event::create(['name' => 'Shower', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $everyone = Event::create(['name' => 'Reception', 'visibility' => 'everyone', 'timezone' => 'UTC']);
        $specific->members()->attach($approved);
        $this->actingAs($this->admin)->putJson('/api/admin/events/attendees/'.$pending->id.'/access', ['event_ulids' => [$specific->ulid]])->assertUnprocessable();
        $this->putJson('/api/admin/events/attendees/'.$approved->id.'/access', ['event_ulids' => [$everyone->ulid]])
            ->assertUnprocessable()->assertJsonValidationErrors('event_ulids.0');
        $this->assertTrue($specific->members()->whereKey($approved->id)->exists());
        $this->assertSame(1, $specific->members()->count());
    }

    public function test_listing_is_paginated_and_approval_does_not_grant_specific_event_membership(): void
    {
        User::factory()->pendingApproval()->count(32)->create();
        $guest = User::factory()->pendingApproval()->create();
        Event::create(['name' => 'Private gathering', 'visibility' => 'specific', 'timezone' => 'UTC']);
        $this->actingAs($this->admin)->getJson('/api/admin/events/attendees?status=pending')->assertOk()
            ->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 33)->assertJsonPath('meta.has_more', true);
        $this->postJson('/api/admin/users/'.$guest->id.'/approve')->assertOk();
        $this->actingAs($guest->refresh())->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
    }
}

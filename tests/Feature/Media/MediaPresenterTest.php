<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use App\Support\MediaPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaPresenterTest extends TestCase
{
    use RefreshDatabase;

    private const MODERATION_KEYS = [
        'moderation_status',
        'moderation_notes',
        'moderated_at',
        'moderated_by_user_id',
    ];

    public function test_owner_view_never_exposes_moderation_state(): void
    {
        $media = Media::factory()->rejected()->create(['original_filename' => 'identifying-name.jpg']);

        $payload = MediaPresenter::ownerView($media);

        foreach (self::MODERATION_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "owner view leaked {$key}");
        }
        $this->assertArrayNotHasKey('user', $payload);
        $this->assertSame('identifying-name.jpg', $payload['original_filename']);
    }

    public function test_visitor_view_omits_owner_only_filename_and_review_state(): void
    {
        $media = Media::factory()->approved()->create(['original_filename' => 'identifying-name.jpg']);

        $payload = MediaPresenter::visitorView($media);

        $this->assertArrayNotHasKey('original_filename', $payload);
        $this->assertArrayNotHasKey('under_review', $payload);
        $this->assertArrayNotHasKey('editable', $payload);
        foreach (self::MODERATION_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "visitor view leaked {$key}");
        }
    }

    public function test_owner_detail_view_exposes_editable_fields_only_when_the_management_relations_are_loaded(): void
    {
        $owner = User::factory()->create();
        $persona = $owner->characters()->create([
            'display_name' => 'Kira',
            'description' => null,
            'audience' => 'everyone',
            'discoverable' => true,
        ]);
        $allowed = User::factory()->create();
        $media = Media::factory()->for($owner)->create([
            'title' => 'Owner title',
            'audience' => 'specific',
            'discoverable' => false,
        ]);
        $media->syncAudienceMembers([$allowed->id]);
        $media->load(['audienceMembers', 'user.characters:id,user_id,display_name']);

        $ownerPayload = MediaPresenter::ownerView($media);
        $visitorPayload = MediaPresenter::visitorView($media);

        $this->assertSame([
            'title' => 'Owner title',
            'character_id' => null,
            'audience' => 'specific',
            'audience_user_ids' => [$allowed->id],
            'discoverable' => false,
            'characters' => [
                ['id' => $persona->id, 'display_name' => 'Kira'],
            ],
        ], $ownerPayload['editable']);
        $this->assertArrayNotHasKey('editable', $visitorPayload);
    }

    public function test_admin_view_includes_moderation_state(): void
    {
        $media = Media::factory()->approved()->create();

        $payload = MediaPresenter::adminView($media);

        foreach (self::MODERATION_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertSame('approved', $payload['moderation_status']);
        $this->assertArrayHasKey('user', $payload);
    }
}

<?php

namespace Tests\Feature\Media;

use App\Models\Media;
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
        foreach (self::MODERATION_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "visitor view leaked {$key}");
        }
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

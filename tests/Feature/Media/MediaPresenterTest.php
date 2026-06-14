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
        $media = Media::factory()->rejected()->create();

        $payload = MediaPresenter::ownerView($media);

        foreach (self::MODERATION_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "owner view leaked {$key}");
        }
        $this->assertArrayNotHasKey('user', $payload);
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

<?php

namespace Tests\Feature\Auth;

use App\Enums\MediaPurpose;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'gender' => $user->gender,
            'gender_other' => $user->gender === 'other' ? $user->gender_other : '',
            'user_type' => $user->user_type,
            'user_type_other' => $user->user_type === 'other' ? $user->user_type_other : '',
            'preferred_user_types' => $user->preferred_user_types,
            'preferred_genders' => $user->preferred_genders,
        ], $overrides);
    }

    private function fakeStorage(): void
    {
        $this->mock(FileStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSignedUploadUrl')->andReturn([
                'url' => 'https://r2.example/put',
                'headers' => ['Content-Type' => 'image/jpeg'],
            ]);
            $mock->shouldReceive('getSignedViewUrl')->andReturn('https://r2.example/view');
            $mock->shouldReceive('fileExists')->andReturn(true);
            $mock->shouldReceive('getFileSize')->andReturn(2048);
            $mock->shouldReceive('deleteFile')->andReturn(true);
        });
    }

    #[Test]
    public function users_can_create_and_complete_profile_picture_uploads(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $response = $this->actingAs($user)->postJson('/api/account/profile-picture', [
            'filename' => 'avatar.jpg',
            'content_type' => 'image/jpeg',
            'size' => 2048,
        ])->assertCreated()
            ->assertJsonPath('upload_url', 'https://r2.example/put')
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.purpose', 'profile_picture')
            ->assertJsonMissingPath('data.moderation_status');

        $media = Media::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($media->isProfilePicture());
        $this->assertSame(MediaPurpose::ProfilePicture, $media->purpose);

        $this->actingAs($user)->postJson("/api/account/profile-picture/{$media->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.upload_status', 'ready');

        $user->refresh();
        $this->assertSame($media->id, $user->profile_picture_media_id);
        $this->assertTrue($media->fresh()->isPendingReview());
    }

    #[Test]
    public function profile_picture_uploads_must_be_images(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->postJson('/api/account/profile-picture', [
            'filename' => 'avatar.mp4',
            'content_type' => 'video/mp4',
            'size' => 2048,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('content_type');
    }

    #[Test]
    public function profile_picture_uploads_do_not_appear_in_gallery_listing(): void
    {
        $this->fakeStorage();
        $user = User::factory()->approved()->create();
        Media::factory()->for($user)->create();
        Media::factory()->for($user)->profilePicture()->create();

        $this->actingAs($user)->getJson('/api/media')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purpose', 'gallery');
    }

    #[Test]
    public function authenticated_users_can_update_name_and_email(): void
    {
        Notification::fake();

        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'display_name' => 'Original Display',
            'birth_date' => '1990-01-15',
            'email' => 'original@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'name' => 'Updated Name',
            'display_name' => 'Updated Display',
            'email' => 'updated@example.com',
        ]))->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('Updated Display', $user->display_name);
        $this->assertSame('1990-01-15', $user->birth_date?->toDateString());
        $this->assertSame('updated@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function users_cannot_change_name_when_name_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'name_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'name' => 'Updated Name',
        ]))->assertStatus(403)
            ->assertJsonPath('message', 'Your real name is locked and cannot be changed.');
    }

    #[Test]
    public function users_can_change_display_name_when_real_name_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'name' => 'Original Name',
            'display_name' => 'Original Display',
            'name_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'name' => 'Original Name',
            'display_name' => 'Updated Display',
        ]))->assertOk()
            ->assertJsonPath('data.display_name', 'Updated Display');

        $user->refresh();
        $this->assertSame('Original Name', $user->name);
        $this->assertSame('Updated Display', $user->display_name);
    }

    #[Test]
    public function users_cannot_change_email_when_email_is_locked(): void
    {
        $user = User::factory()->approved()->create([
            'email' => 'original@example.com',
            'email_locked' => true,
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'email' => 'updated@example.com',
        ]))->assertStatus(403)
            ->assertJsonPath('message', 'Your email is locked and cannot be changed.');
    }

    #[Test]
    public function users_cannot_use_an_existing_email(): void
    {
        User::factory()->approved()->create(['email' => 'existing@example.com']);
        $user = User::factory()->approved()->create(['email' => 'original@example.com']);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'name' => 'Updated Name',
            'email' => 'existing@example.com',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function users_cannot_change_birth_date_from_account_settings(): void
    {
        $user = User::factory()->approved()->create([
            'birth_date' => '1990-01-15',
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'birth_date' => '1989-01-15',
        ]))->assertOk();

        $this->assertSame('1990-01-15', $user->fresh()->birth_date?->toDateString());
    }

    #[Test]
    public function users_can_update_account_fields_when_profile_choices_are_blank(): void
    {
        Notification::fake();

        $user = User::factory()->approved()->create([
            'email' => 'blank-profile@example.com',
            'email_verified_at' => now(),
            'gender' => null,
            'gender_other' => null,
            'user_type' => null,
            'user_type_other' => null,
            'preferred_user_types' => null,
            'preferred_genders' => null,
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'name' => 'Blank Profile Updated',
            'email' => 'blank-profile-updated@example.com',
        ]))->assertOk()
            ->assertJsonPath('data.gender', null)
            ->assertJsonPath('data.user_type', null)
            ->assertJsonPath('data.preferred_user_types', null)
            ->assertJsonPath('data.preferred_genders', null);

        $user->refresh();
        $this->assertSame('Blank Profile Updated', $user->name);
        $this->assertSame('blank-profile-updated@example.com', $user->email);
        $this->assertNull($user->gender);
        $this->assertNull($user->user_type);
        $this->assertNull($user->preferred_user_types);
        $this->assertNull($user->preferred_genders);
    }

    #[Test]
    public function users_can_update_identity_and_discovery_preferences(): void
    {
        $user = User::factory()->approved()->create([
            'gender' => 'male',
            'user_type' => 'human',
            'preferred_user_types' => ['human'],
            'preferred_genders' => ['female'],
        ]);

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'gender' => 'other',
            'gender_other' => 'Nonbinary',
            'user_type' => 'other',
            'user_type_other' => 'Therian',
            'preferred_user_types' => ['furry', 'other'],
            'preferred_genders' => ['male', 'other'],
        ]))->assertOk()
            ->assertJsonPath('data.gender', 'other')
            ->assertJsonPath('data.gender_other', 'Nonbinary')
            ->assertJsonPath('data.user_type', 'other')
            ->assertJsonPath('data.user_type_other', 'Therian')
            ->assertJsonPath('data.preferred_user_types', ['furry', 'other'])
            ->assertJsonPath('data.preferred_genders', ['male', 'other']);

        $user->refresh();
        $this->assertSame('other', $user->gender);
        $this->assertSame('Nonbinary', $user->gender_other);
        $this->assertSame('other', $user->user_type);
        $this->assertSame('Therian', $user->user_type_other);
        $this->assertSame(['furry', 'other'], $user->preferred_user_types);
        $this->assertSame(['male', 'other'], $user->preferred_genders);
    }

    #[Test]
    public function users_can_clear_discovery_preferences(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)->patchJson('/api/account', $this->profilePayload($user, [
            'preferred_user_types' => [],
            'preferred_genders' => [],
        ]))->assertOk()
            ->assertJsonPath('data.preferred_user_types', [])
            ->assertJsonPath('data.preferred_genders', []);

        $user->refresh();
        $this->assertSame([], $user->preferred_user_types);
        $this->assertSame([], $user->preferred_genders);
    }

    #[Test]
    public function account_settings_do_not_default_blank_identity_fields(): void
    {
        $user = User::factory()->approved()->create([
            'gender' => null,
            'gender_other' => null,
            'user_type' => null,
            'user_type_other' => null,
            'preferred_user_types' => null,
            'preferred_genders' => null,
        ]);

        $content = $this->actingAs($user)->get('/user/settings')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<script id="user-settings-initial-data"[^>]*>\s*(.*?)\s*<\/script>/s',
            $content,
        );
        preg_match('/<script id="user-settings-initial-data"[^>]*>\s*(.*?)\s*<\/script>/s', $content, $matches);

        /** @var array<string, mixed> $data */
        $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($data['gender']);
        $this->assertNull($data['user_type']);
        $this->assertSame([], $data['preferred_user_types']);
        $this->assertSame([], $data['preferred_genders']);
    }
}

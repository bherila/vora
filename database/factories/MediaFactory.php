<?php

namespace Database\Factories;

use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Enums\Visibility;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $ulid = (string) Str::ulid();
        $type = MediaType::Photo;

        return [
            'user_id' => User::factory(),
            'ulid' => $ulid,
            'type' => $type,
            'disk' => $type->disk(),
            'object_key' => 'uploads/0/'.$ulid.'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'title' => fake()->optional()->sentence(3),
            'upload_status' => 'ready',
            'visibility' => Visibility::Users,
            'moderation_status' => ModerationStatus::Pending,
        ];
    }

    public function video(): static
    {
        return $this->state(function (): array {
            $type = MediaType::Video;

            return [
                'type' => $type,
                'disk' => $type->disk(),
                'mime_type' => 'video/mp4',
                'object_key' => 'uploads/0/'.(string) Str::ulid().'.mp4',
                'original_filename' => fake()->word().'.mp4',
            ];
        });
    }

    public function unlisted(): static
    {
        return $this->state(fn (): array => ['visibility' => Visibility::Unlisted]);
    }

    public function pendingUpload(): static
    {
        return $this->state(fn (): array => ['upload_status' => 'pending', 'size_bytes' => null]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['moderation_status' => ModerationStatus::Rejected]);
    }
}

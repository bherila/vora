<?php

namespace Database\Factories;

use App\Enums\RestrictionCapability;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserRestriction> */
class UserRestrictionFactory extends Factory
{
    protected $model = UserRestriction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'capability' => RestrictionCapability::MediaUpload,
            'reason' => fake()->sentence(),
        ];
    }

    public function capability(RestrictionCapability $capability): static
    {
        return $this->state(fn (): array => ['capability' => $capability]);
    }
}

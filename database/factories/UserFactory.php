<?php

namespace Database\Factories;

use App\Enums\Audience;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'display_name' => $name,
            'birth_date' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'gender' => 'male',
            'user_type' => 'human',
            'profile_audience' => Audience::Everyone,
            'preferred_user_types' => ['human', 'furry', 'other'],
            'preferred_genders' => ['male', 'female', 'other'],
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Admin-approved account ready to use the app.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'approved_at' => now(),
        ]);
    }

    /**
     * Verified + admin-approved (non-admin) account.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
        ]);
    }

    /**
     * Verified but awaiting admin approval.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }

    /**
     * Hard-disabled / rejected account.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_disabled' => true,
        ]);
    }
}

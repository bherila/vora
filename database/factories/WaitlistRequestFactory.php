<?php

namespace Database\Factories;

use App\Models\WaitlistRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WaitlistRequest>
 */
class WaitlistRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'email' => fake()->unique()->safeEmail(),
            'birth_date' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'interests' => fake()->paragraph(),
            'ip_address' => fake()->ipv4(),
            'geo' => ['country' => 'US'],
            'verification_code_hash' => null,
            'verification_token_hash' => null,
            'verified_at' => null,
            'admitted_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
        ]);
    }

    public function admitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
            'admitted_at' => now(),
        ]);
    }
}

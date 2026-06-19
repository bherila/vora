<?php

namespace Database\Factories;

use App\Models\InviteGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InviteGrant>
 */
class InviteGrantFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);

        return [
            'user_id' => User::factory(),
            'quantity' => $quantity,
            'remaining' => $quantity,
            'expires_at' => null,
            'issued_by_user_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresInDays(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays($days),
        ]);
    }
}

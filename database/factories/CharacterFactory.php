<?php

namespace Database\Factories;

use App\Enums\Audience;
use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'user_id' => User::factory(),
            'display_name' => fake()->name(),
            'description' => fake()->optional()->sentence(),
            'audience' => Audience::Everyone,
            'discoverable' => true,
            'gender' => 'male',
            'user_type' => 'human',
            'inherit_interests' => true,
            'is_linked' => true,
        ];
    }

    public function audience(Audience $audience): static
    {
        return $this->state(fn (): array => ['audience' => $audience]);
    }
}

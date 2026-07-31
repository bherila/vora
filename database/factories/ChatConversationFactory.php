<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChatConversation> */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        $first = User::factory()->approved()->create();
        $second = User::factory()->approved()->create();

        return [
            'ulid' => (string) Str::ulid(),
            'lower_user_id' => min($first->id, $second->id),
            'higher_user_id' => max($first->id, $second->id),
        ];
    }
}

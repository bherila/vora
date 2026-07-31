<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatParticipant> */
class ChatParticipantFactory extends Factory
{
    protected $model = ChatParticipant::class;

    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'user_id' => fn (array $attributes): int => ChatConversation::query()
                ->findOrFail($attributes['conversation_id'])
                ->lower_user_id,
            'unread_count' => 0,
        ];
    }
}

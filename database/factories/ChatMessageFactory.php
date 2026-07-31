<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChatMessage> */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'conversation_id' => ChatConversation::factory(),
            'sender_user_id' => fn (array $attributes): int => ChatConversation::query()
                ->findOrFail($attributes['conversation_id'])
                ->lower_user_id,
            'sender_public_ulid' => fn (array $attributes): string => ChatConversation::query()
                ->findOrFail($attributes['conversation_id'])
                ->lowerUser
                ->public_ulid,
            'sender_public_name' => fn (array $attributes): string => ChatConversation::query()
                ->findOrFail($attributes['conversation_id'])
                ->lowerUser
                ->display_name,
            'client_message_id' => (string) Str::ulid(),
            'body' => fake()->sentence(),
        ];
    }
}

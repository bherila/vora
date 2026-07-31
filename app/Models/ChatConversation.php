<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'lower_user_id',
        'higher_user_id',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function lowerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lower_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function higherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'higher_user_id');
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /** @return HasOne<ChatMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }

    public function otherUserId(int $viewerId): ?int
    {
        return match ($viewerId) {
            $this->lower_user_id => $this->higher_user_id,
            $this->higher_user_id => $this->lower_user_id,
            default => null,
        };
    }
}

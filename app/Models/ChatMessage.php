<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ulid',
        'conversation_id',
        'sender_user_id',
        'sender_public_ulid',
        'sender_public_name',
        'client_message_id',
        'body',
    ];

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function readByParticipants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'last_read_message_id');
    }
}

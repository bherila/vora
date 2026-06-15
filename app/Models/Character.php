<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Character extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'display_name',
        'description',
        'gender',
        'gender_other',
        'preferred_user_types',
        'preferred_genders',
        'profile_picture_media_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_user_types' => 'array',
            'preferred_genders' => 'array',
            'profile_picture_media_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function profilePicture(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'profile_picture_media_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecentProfileVisit extends Model
{
    public const TARGET_USER = 'user';

    public const TARGET_CHARACTER = 'character';

    protected $fillable = [
        'viewer_user_id',
        'target_type',
        'target_id',
        'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}

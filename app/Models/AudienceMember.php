<?php

namespace App\Models;

use App\Traits\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single grant on a content item's "specific people" allowlist: one user who
 * may view one privacy-controlled record. Polymorphic so the same table serves
 * Media, Story, and any future content via {@see HasPrivacyPolicy}.
 *
 * Rows are removed when the granted user is deleted (FK cascade) and when the
 * host content is deleted (model cleanup), so a deleted account never lingers on
 * anyone's allowlist — supporting the right-to-erasure requirement.
 */
class AudienceMember extends Model
{
    protected $fillable = ['user_id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function privacyable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

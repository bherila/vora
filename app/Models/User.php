<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * Mass-assignable attributes. Privilege/approval columns are intentionally
     * excluded — they are set only through admin flows, never from registration input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'birth_date',
        'email',
        'password',
        'gender',
        'gender_other',
        'user_type',
        'user_type_other',
        'profile_audience',
        'preferred_user_types',
        'preferred_genders',
        'last_login_at',
        'notify_new_post',
        'notify_post_reaction',
        'notify_post_comment',
        'notify_follow_request',
        'notify_follow_accepted',
        'profile_picture_media_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'approved_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'id_verified_at' => 'datetime',
            'birth_date' => 'date',
            'profile_audience' => Audience::class,
            'preferred_user_types' => 'array',
            'preferred_genders' => 'array',
            'last_media_interest_ids' => 'array',
            'profile_picture_media_id' => 'integer',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_disabled' => 'boolean',
            'name_locked' => 'boolean',
            'email_locked' => 'boolean',
            'force_change_pw' => 'boolean',
            'notify_new_post' => 'boolean',
            'notify_post_reaction' => 'boolean',
            'notify_post_comment' => 'boolean',
            'notify_follow_request' => 'boolean',
            'notify_follow_accepted' => 'boolean',
        ];
    }

    /**
     * User id 1 is always an admin; otherwise the explicit flag governs.
     */
    public function isAdmin(): bool
    {
        return $this->id === 1 || $this->is_admin === true;
    }

    /**
     * Approved by an admin (or grandfathered via approved_at being set).
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Registered but not yet approved (and not disabled).
     */
    public function isPendingApproval(): bool
    {
        return $this->approved_at === null && ! $this->is_disabled;
    }

    /**
     * Hard-disabled / rejected accounts cannot authenticate at all.
     */
    public function canLogin(): bool
    {
        return ! $this->is_disabled;
    }

    /**
     * Self-deactivated accounts can still log in, but are gated to the
     * reactivate page and hidden from other users until they reactivate.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Whether this account is visible to and interactable with by other users:
     * not self-deactivated and not admin-disabled. Soft-deleted users resolve to
     * null before this is ever reached. The single source of truth shared with
     * {@see self::scopeActive()}; mirror any change there.
     */
    public function isActive(): bool
    {
        return ! $this->isDeactivated() && $this->canLogin();
    }

    /**
     * Query counterpart to {@see self::isActive()}: limit to accounts that are
     * neither deactivated nor disabled. Soft-deleted rows are already excluded by
     * the model's default scope.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at')->where('is_disabled', false);
    }

    /**
     * Where an approved user lands after authenticating.
     */
    public function getLoginRedirectUrl(): string
    {
        return '/';
    }

    /**
     * The admin who approved this account.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<InterestRating, $this>
     */
    public function interestRatings(): HasMany
    {
        return $this->hasMany(InterestRating::class);
    }

    /**
     * @return HasMany<InterestRequest, $this>
     */
    public function interestRequests(): HasMany
    {
        return $this->hasMany(InterestRequest::class);
    }

    /**
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The "specific people" allowlist for this user's profile, reusing the shared
     * polymorphic audience_members table with the User as the privacyable.
     *
     * @return MorphMany<AudienceMember, $this>
     */
    public function profileAudienceMembers(): MorphMany
    {
        return $this->morphMany(AudienceMember::class, 'privacyable');
    }

    /**
     * Optional fictional personas owned by this account. The user account itself
     * remains the default/null character for follows and profile changes.
     *
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function profilePicture(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'profile_picture_media_id');
    }

    /**
     * @return HasOne<Media, $this>
     */
    public function latestProfilePictureUpload(): HasOne
    {
        return $this->hasOne(Media::class)->where('purpose', 'profile_picture')->latestOfMany();
    }

    /**
     * Stories this user owns (created). Co-authored stories are reached through
     * {@see static::storyAuthorships()}.
     *
     * @return HasMany<Story, $this>
     */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /**
     * Every authorship row for this user (owner and co-author, pending and
     * accepted).
     *
     * @return HasMany<StoryAuthor, $this>
     */
    public function storyAuthorships(): HasMany
    {
        return $this->hasMany(StoryAuthor::class);
    }

    /**
     * @return HasMany<FollowRequest, $this>
     */
    public function sentFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'requester_id');
    }

    /**
     * @return HasMany<FollowRequest, $this>
     */
    public function receivedFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'recipient_id');
    }
}

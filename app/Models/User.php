<?php

namespace App\Models;

use App\Enums\Audience;
use App\Http\Middleware\EnsureNotBanned;
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
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use HasPushSubscriptions;
    use Notifiable;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Mass-assignable attributes. Privilege/approval columns are intentionally
     * excluded — they are set only through admin flows, never from registration input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'bio',
        'pronouns',
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
        'notify_co_author_invite',
        'notify_co_author_invite_accepted',
        'notify_favorite',
        'notify_message',
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
            'onboarding_dismissed_at' => 'datetime',
            'approved_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'id_verified_at' => 'datetime',
            'banned_at' => 'datetime',
            'ban_appeal_at' => 'datetime',
            'legal_hold_at' => 'datetime',
            'birth_date' => 'date',
            'profile_audience' => Audience::class,
            'preferred_user_types' => 'array',
            'preferred_genders' => 'array',
            'last_media_interest_ids' => 'array',
            'profile_picture_media_id' => 'integer',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_disabled' => 'boolean',
            'can_receive_invites' => 'boolean',
            'ban_hides_content' => 'boolean',
            'trusted_inviter' => 'boolean',
            'name_locked' => 'boolean',
            'email_locked' => 'boolean',
            'force_change_pw' => 'boolean',
            'notify_new_post' => 'boolean',
            'notify_post_reaction' => 'boolean',
            'notify_post_comment' => 'boolean',
            'notify_follow_request' => 'boolean',
            'notify_follow_accepted' => 'boolean',
            'notify_co_author_invite' => 'boolean',
            'notify_co_author_invite_accepted' => 'boolean',
            'notify_favorite' => 'boolean',
            'notify_message' => 'boolean',
            'chat_sync_version' => 'integer',
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
     * Banned by an admin. Banned users can still log in but are gated by
     * {@see EnsureNotBanned} to appeal/deactivate/delete.
     */
    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * Whether the ban also hides the user's content from others. A ban without
     * this flag is "memorialized" — the account is gated but content stays visible.
     */
    public function banHidesContent(): bool
    {
        return $this->isBanned() && $this->ban_hides_content === true;
    }

    /**
     * Admin-only legal hold: blocks account deletion (only). Independent of the
     * ban state and never surfaced to the user until they attempt to delete.
     */
    public function isOnLegalHold(): bool
    {
        return $this->legal_hold_at !== null;
    }

    /**
     * Whether this user's invitees skip the admin approval gate (auto-approved).
     */
    public function isTrustedInviter(): bool
    {
        return $this->trusted_inviter === true;
    }

    /**
     * Whether this account is visible to and interactable with by other users:
     * not self-deactivated, not admin-disabled, and not banned-with-content-hidden.
     * Soft-deleted users resolve to null before this is ever reached. The single
     * source of truth shared with {@see self::scopeActive()}; mirror any change there.
     */
    public function isActive(): bool
    {
        return ! $this->isDeactivated() && $this->canLogin() && ! $this->banHidesContent();
    }

    /**
     * Query counterpart to {@see self::isActive()}: limit to accounts that are
     * neither deactivated, disabled, nor banned-with-content-hidden. Soft-deleted
     * rows are already excluded by the model's default scope.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at')
            ->where('is_disabled', false)
            ->where(fn (Builder $q) => $q->whereNull('banned_at')->orWhere('ban_hides_content', false));
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
     * Invite grants issued to this user (their invite balance lives here).
     *
     * @return HasMany<InviteGrant, $this>
     */
    public function inviteGrants(): HasMany
    {
        return $this->hasMany(InviteGrant::class);
    }

    /**
     * Invite links this user has generated.
     *
     * @return HasMany<Invite, $this>
     */
    public function sentInvites(): HasMany
    {
        return $this->hasMany(Invite::class, 'inviter_user_id');
    }

    /**
     * The invite that referred this account, if any (null = no inviter). The
     * inviter is reached via {@see Invite::inviter()}, forming the invite tree.
     *
     * @return BelongsTo<Invite, $this>
     */
    public function referredByInvite(): BelongsTo
    {
        return $this->belongsTo(Invite::class, 'referred_by_invite_id');
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
     * The user's saved favorites (polymorphic over media/story/post/user/character).
     *
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
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

    /**
     * @return HasMany<Block, $this>
     */
    public function blocksMade(): HasMany
    {
        return $this->hasMany(Block::class, 'blocker_id');
    }

    /**
     * @return HasMany<Block, $this>
     */
    public function blocksReceived(): HasMany
    {
        return $this->hasMany(Block::class, 'blocked_user_id');
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function chatParticipations(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function sentChatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_user_id');
    }

    /**
     * Viewer-private recently visited profile references.
     *
     * @return HasMany<RecentProfileVisit, $this>
     */
    public function recentProfileVisits(): HasMany
    {
        return $this->hasMany(RecentProfileVisit::class, 'viewer_user_id');
    }
}

<?php

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\AdminDeletedContentController;
use App\Http\Controllers\Admin\AdminInterestController;
use App\Http\Controllers\Admin\AdminInviteController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminMediaDuplicateController;
use App\Http\Controllers\Admin\AdminPostCommentController;
use App\Http\Controllers\Admin\AdminPostController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminStaticPageController;
use App\Http\Controllers\Admin\AdminStoryController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWaitlistController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterProfileController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\Follow\FollowController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PostCommentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostReactionController;
use App\Http\Controllers\ProfileContentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SideRailController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\Story\AuthorshipInviteController;
use App\Http\Controllers\Story\StoryAuthorController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

// Home page and static pages (public).
Route::get('/', [StaticPageController::class, 'home'])->name('home');
Route::get('/privacy', [StaticPageController::class, 'show'])->defaults('slug', 'privacy')->name('privacy');
Route::get('/terms', [StaticPageController::class, 'show'])->defaults('slug', 'terms')->name('terms');
Route::get('/page/{slug}', [StaticPageController::class, 'show'])->name('pages.show');

/*
|--------------------------------------------------------------------------
| Guest auth pages
|--------------------------------------------------------------------------
| The bherila/auth-laravel package owns the JSON APIs (password reset, passkeys,
| 2FA); these routes own the pages and the password-login entrypoint.
*/
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::post('/api/auth/register', [RegisterController::class, 'store']);
    Route::get('/i/{uuid}', [RegisterController::class, 'landing'])->name('invite.landing');

    // Public waitlist ("request an invitation"). Active only while public signups
    // are closed — the controller redirects to /register otherwise.
    Route::get('/request-invitation', [WaitlistController::class, 'show'])->name('waitlist.request');
    Route::post('/api/waitlist', [WaitlistController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/waitlist/verify/{uuid}/{token}', [WaitlistController::class, 'verifyLink'])->name('waitlist.verify');
    Route::post('/api/waitlist/verify', [WaitlistController::class, 'verifyCode'])->middleware('throttle:6,1');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/api/auth/login', [LoginController::class, 'store']);
    Route::get('/login/two-factor/{token}', [LoginController::class, 'showTwoFactor'])->name('login.two-factor');

    Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]))->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Authenticated (no approval gate — these are the gate pages themselves)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::patch('/api/account', [ProfileController::class, 'update']);
    Route::post('/api/account/profile-picture', [ProfileController::class, 'storeProfilePicture']);
    Route::post('/api/account/profile-picture/{media}/complete', [ProfileController::class, 'completeProfilePicture']);
    Route::delete('/api/account/profile-picture', [ProfileController::class, 'removeProfilePicture']);
    Route::get('/api/account/export', [ProfileController::class, 'export']);
    Route::post('/api/account/deactivate', [ProfileController::class, 'deactivate']);
    Route::post('/api/account/delete', [ProfileController::class, 'destroy']);

    Route::get('/api/push-subscriptions', [PushSubscriptionController::class, 'status']);
    Route::post('/api/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/api/push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    // Viewer-side identity mutes. Settings is available before approval, so its
    // list/unmute API belongs in the same authenticated route group.
    Route::get('/api/mutes', [MuteController::class, 'index']);
    Route::post('/api/mutes', [MuteController::class, 'store']);
    Route::delete('/api/mutes', [MuteController::class, 'destroy']);

    Route::get('/pending-approval', fn () => view('auth.pending-approval'))->name('approval.pending');
    Route::get('/user/settings', function () {
        $user = auth()->user();

        return view('user.settings', ['initialData' => ['userSettings' => [
            'name' => $user->name,
            'display_name' => $user->display_name,
            'birth_date' => $user->birth_date?->toDateString(),
            'email' => $user->email,
            'gender' => $user->gender,
            'gender_other' => $user->gender_other,
            'user_type' => $user->user_type,
            'user_type_other' => $user->user_type_other,
            'preferred_user_types' => $user->preferred_user_types ?? [],
            'preferred_genders' => $user->preferred_genders ?? [],
            'profile_audience' => $user->profile_audience?->value ?? 'everyone',
            'audience_user_ids' => $user->profileAudienceMembers()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            'id_verified_at' => $user->id_verified_at?->toIso8601String(),
            'name_locked' => (bool) $user->name_locked,
            'email_locked' => (bool) $user->email_locked,
            'notify_new_post' => (bool) $user->notify_new_post,
            'notify_post_reaction' => (bool) $user->notify_post_reaction,
            'notify_post_comment' => (bool) $user->notify_post_comment,
            'notify_follow_request' => (bool) $user->notify_follow_request,
            'notify_follow_accepted' => (bool) $user->notify_follow_accepted,
            'notify_co_author_invite' => (bool) $user->notify_co_author_invite,
            'notify_co_author_invite_accepted' => (bool) $user->notify_co_author_invite_accepted,
            'notify_favorite' => (bool) $user->notify_favorite,
            'web_push_public_key' => config('webpush.vapid.public_key'),
            'web_push_subscription_count' => $user->pushSubscriptions()->count(),
            'can_manage_interests' => (bool) (! $user->is_disabled && $user->hasVerifiedEmail() && $user->isApproved()),
        ]]]);
    })->name('user.settings');

    // Reachable while deactivated (exempt in EnsureNotDeactivated) so the user
    // can reactivate or sign out.
    Route::get('/account/deactivated', fn () => view('auth.deactivated'))->name('account.deactivated');
    Route::post('/account/reactivate', [ProfileController::class, 'reactivate'])->name('account.reactivate');

    // Reachable while banned (exempt in EnsureNotBanned) so the user can appeal,
    // deactivate, delete, or sign out.
    Route::get('/account/banned', [ProfileController::class, 'bannedPage'])->name('account.banned');
    Route::post('/api/account/appeal', [ProfileController::class, 'appeal'])->name('account.appeal');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Authenticated + verified + approved app area
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'approved'])->group(function () {
    // The retired dashboard forwards old bookmarks and route callers to the feed.
    Route::get('/dashboard', fn () => redirect()->route('feed'))->name('dashboard');
    Route::get('/feed', [FeedController::class, 'page'])->name('feed');
    Route::post('/api/onboarding/dismiss', [OnboardingController::class, 'dismiss']);
    Route::post('/api/identity', [IdentityController::class, 'update']);
    Route::get('/api/side-rail', [SideRailController::class, 'show']);
    Route::delete('/api/side-rail/history', [SideRailController::class, 'clearHistory']);
    Route::delete('/api/blocks/{block}', [BlockController::class, 'destroy']);
    // Keep the retired characters index pointed at the profile for old bookmarks;
    // create/edit use the dedicated persona editor routes below.
    Route::get('/characters', fn () => redirect()->route('me'))->name('characters');
    Route::get('/personas/new', [CharacterController::class, 'createPage'])->name('characters.create');

    // Invites the user can hand out (balance issued by an admin).
    Route::get('/user/invites', [InviteController::class, 'page'])->name('user.invites');
    Route::get('/api/invites', [InviteController::class, 'apiIndex']);
    Route::post('/api/invites', [InviteController::class, 'generate']);
    Route::delete('/api/invites/{invite}', [InviteController::class, 'revoke']);

    // /media is retired: media now lives on the profile (upload + management) and
    // Explore is the cross-profile browse. Redirect old links/bookmarks there.
    Route::get('/media', fn () => redirect()->route('explore'))->name('media');
    Route::get('/m/{ulid}', [MediaController::class, 'viewPage'])->name('media.view');

    // Cross-user exploration of approved, discoverable media.
    Route::get('/explore', [ExploreController::class, 'page'])->name('explore');
    Route::get('/api/explore', [ExploreController::class, 'apiIndex']);
    Route::get('/api/explore/stories', [ExploreController::class, 'apiStories']);
    Route::get('/api/explore/personas', [ExploreController::class, 'apiPersonas']);

    // A persona's public profile, resolved by ulid. Gated on the character's
    // own audience — deliberately independent of the owner's profile gate.
    Route::get('/c/{ulid}/edit', [CharacterController::class, 'editPage'])->name('characters.edit');
    Route::get('/c/{ulid}', [CharacterProfileController::class, 'page'])->name('characters.view');
    Route::prefix('api/c/{ulid}')->group(function () {
        Route::get('/counts', [CharacterProfileController::class, 'counts']);
        Route::get('/media', [CharacterProfileController::class, 'media']);
        Route::get('/stories', [CharacterProfileController::class, 'stories']);
        Route::get('/posts', [CharacterProfileController::class, 'posts']);
    });

    // Stories workspace + shareable single-story reader.
    Route::get('/stories', [StoryController::class, 'page'])->name('stories');
    Route::get('/s/{ulid}', [StoryController::class, 'readerPage'])->name('stories.view');

    // The signed-in user's own profile (the same container view as other people's
    // profiles, in owner mode).
    Route::get('/me', [FollowController::class, 'me'])->name('me');

    Route::get('/users', [FollowController::class, 'directory'])->name('users.directory');
    Route::get('/users/follow-requests', [FollowController::class, 'inboxPage'])->name('users.follow-requests');
    Route::get('/users/{user}', [FollowController::class, 'profilePage'])->name('users.profile');

    Route::prefix('api/characters')->group(function () {
        Route::get('/', [CharacterController::class, 'index']);
        Route::post('/', [CharacterController::class, 'store']);
        Route::patch('/{character}', [CharacterController::class, 'update']);
        Route::delete('/{character}', [CharacterController::class, 'destroy']);
        Route::get('/{character}/followers', [FollowController::class, 'characterFollowers']);
        Route::post('/{character}/follow', [FollowController::class, 'followCharacter']);
        Route::post('/{blockCharacter}/block', [BlockController::class, 'blockCharacter']);
        Route::delete('/{character}/block', [BlockController::class, 'unblockCharacter']);
        Route::post('/{character}/profile-picture', [CharacterController::class, 'storeProfilePicture']);
        Route::post('/{character}/profile-picture/{media}/complete', [CharacterController::class, 'completeProfilePicture']);
        Route::delete('/{character}/profile-picture', [CharacterController::class, 'removeProfilePicture']);
    });

    // Saved favorites (polymorphic). Toggling is on the current user; the listing
    // is per-profile and privacy-intersected in the controller.
    Route::post('/api/favorites', [FavoriteController::class, 'store']);
    Route::delete('/api/favorites', [FavoriteController::class, 'destroy']);

    // Abuse reports (polymorphic: media, stories, posts). Throttled — a handful
    // of reports a minute is plenty and this blunts report-spam.
    Route::post('/api/reports', [ReportController::class, 'store'])->middleware('throttle:10,1');

    Route::prefix('api/users')->group(function () {
        Route::get('/', [FollowController::class, 'users']);
        Route::get('/follow-requests/count', [FollowController::class, 'count']);
        Route::get('/follow-requests', [FollowController::class, 'inbox']);
        Route::post('/follow-requests/{followRequest}/accept', [FollowController::class, 'accept']);
        Route::post('/follow-requests/{followRequest}/decline', [FollowController::class, 'decline']);
        Route::get('/{user}/favorites', [FavoriteController::class, 'index']);
        Route::get('/{user}/content-counts', [ProfileContentController::class, 'counts']);
        Route::get('/{user}/recent-content', [ProfileContentController::class, 'recent']);
        Route::get('/{user}/media', [ProfileContentController::class, 'media']);
        Route::get('/{user}/stories', [ProfileContentController::class, 'stories']);
        Route::get('/{user}/posts', [ProfileContentController::class, 'posts']);
        Route::get('/{user}', [FollowController::class, 'profile']);
        Route::post('/{user}/follow-requests', [FollowController::class, 'requestFollow']);
        Route::post('/{blockUser}/block', [BlockController::class, 'blockUser']);
        Route::delete('/{user}/block', [BlockController::class, 'unblockUser']);
    });

    Route::prefix('api/stories')->group(function () {
        Route::get('/', [StoryController::class, 'index']);
        Route::post('/', [StoryController::class, 'store']);
        Route::get('/by-ulid/{ulid}', [StoryController::class, 'showByUlid']);
        Route::get('/{story}', [StoryController::class, 'show']);
        Route::patch('/{story}', [StoryController::class, 'update']);
        Route::delete('/{story}', [StoryController::class, 'destroy']);
        Route::put('/{story}/graph', [StoryController::class, 'saveGraph']);
        Route::get('/{story}/authors', [StoryAuthorController::class, 'index']);
        Route::post('/{story}/authors', [StoryAuthorController::class, 'invite']);
        Route::patch('/{story}/authors/{user}', [StoryAuthorController::class, 'update']);
        // withTrashed: a co-author who soft-deleted their account still has a
        // story_authors row the owner must be able to remove.
        Route::delete('/{story}/authors/{user}', [StoryAuthorController::class, 'destroy'])->withTrashed();
    });

    // Co-author invitations — the story side of the shared acceptance inbox.
    Route::prefix('api/authorship-invites')->group(function () {
        Route::get('/', [AuthorshipInviteController::class, 'inbox']);
        Route::get('/count', [AuthorshipInviteController::class, 'count']);
        Route::post('/{storyAuthor}/accept', [AuthorshipInviteController::class, 'accept']);
        Route::post('/{storyAuthor}/decline', [AuthorshipInviteController::class, 'decline']);
    });

    Route::prefix('api/media')->group(function () {
        Route::get('/', [MediaController::class, 'index']);
        Route::post('/', [MediaController::class, 'store']);
        Route::patch('/bulk', [MediaController::class, 'bulkUpdate']);
        Route::delete('/bulk', [MediaController::class, 'bulkDestroy']);
        Route::get('/by-ulid/{ulid}', [MediaController::class, 'showByUlid']);
        Route::get('/by-ulid/{ulid}/asset/{variant}', [MediaAssetController::class, 'show'])
            ->where('variant', 'original|thumbnail')
            ->name('media.asset');
        Route::post('/{media}/multipart/init', [MediaController::class, 'initMultipart']);
        Route::post('/{media}/multipart/parts', [MediaController::class, 'presignMultipartParts']);
        Route::post('/{media}/multipart/complete', [MediaController::class, 'completeMultipart']);
        Route::post('/{media}/multipart/abort', [MediaController::class, 'abortMultipart']);
        // HLS playback proxy: manifests served inline (rewritten), segments 302-redirected to R2.
        Route::get('/{media}/hls/{path?}', [MediaController::class, 'streamHls'])
            ->where('path', '.*')
            ->name('media.hls');
        Route::post('/{media}/complete', [MediaController::class, 'complete']);
        Route::get('/{media}', [MediaController::class, 'show']);
        Route::patch('/{media}', [MediaController::class, 'update']);
        Route::delete('/{media}', [MediaController::class, 'destroy']);
    });

    Route::prefix('api/posts')->group(function () {
        Route::get('/', [PostController::class, 'index']);
        Route::post('/', [PostController::class, 'store']);
        Route::get('/by-ulid/{ulid}', [PostController::class, 'showByUlid']);
        Route::delete('/{post}', [PostController::class, 'destroy']);
        Route::post('/{post}/reactions', [PostReactionController::class, 'store']);
        Route::delete('/{post}/reactions', [PostReactionController::class, 'destroy']);
        Route::get('/{post}/comments', [PostCommentController::class, 'index']);
        Route::post('/{post}/comments', [PostCommentController::class, 'store']);
        Route::delete('/{post}/comments/{comment}', [PostCommentController::class, 'destroy']);
    });

    // Shareable single-post page (the URL notifications link to).
    Route::get('/p/{ulid}', [PostController::class, 'viewPage'])->name('posts.view');

    Route::get('/api/feed', [FeedController::class, 'index']);

    Route::prefix('api/notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin (approved admins only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'approved', 'can:admin-only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users');
    Route::get('/invites', [AdminInviteController::class, 'index'])->name('invites');
    Route::get('/waitlist', [AdminWaitlistController::class, 'index'])->name('waitlist');
    Route::get('/audit-log', [AdminAuditController::class, 'index'])->name('audit-log');
    Route::get('/interests', [AdminInterestController::class, 'index'])->name('interests');
    Route::get('/media', [AdminMediaController::class, 'index'])->name('media');
    Route::get('/media-duplicates', [AdminMediaDuplicateController::class, 'index'])->name('media-duplicates');
    Route::get('/reports', [AdminReportController::class, 'index'])->name('reports');
    Route::get('/stories', [AdminStoryController::class, 'index'])->name('stories');
    Route::get('/deleted-content', [AdminDeletedContentController::class, 'index'])->name('deleted-content');
    Route::get('/pages', [AdminStaticPageController::class, 'index'])->name('pages');
    Route::get('/posts', fn () => view('admin.posts'))->name('posts');
});

// Admin JSON API — session-authenticated (web middleware), admin-gated. The
// admin-only ability already enforces the full access model (admin + approved +
// not disabled) and returns a clean 403 for JSON callers.
Route::middleware(['auth', 'approved', 'can:admin-only'])->prefix('api/admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'apiIndex']);
    Route::post('/users/{user}/approve', [AdminUserController::class, 'approve']);
    Route::patch('/users/{user}', [AdminUserController::class, 'update']);
    Route::post('/users/{user}/ban', [AdminUserController::class, 'ban']);
    Route::post('/users/{user}/unban', [AdminUserController::class, 'unban']);
    Route::post('/users/{user}/legal-hold', [AdminUserController::class, 'legalHold']);
    Route::post('/users/{user}/invites', [AdminUserController::class, 'issueInvites']);
    // Purge/restore also operate on soft-deleted users, so include trashed in the binding.
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->withTrashed();
    Route::post('/users/{user}/restore', [AdminUserController::class, 'restore'])->withTrashed();

    Route::get('/invites', [AdminInviteController::class, 'apiIndex']);
    Route::put('/invites/settings', [AdminInviteController::class, 'updateSettings']);
    Route::post('/invites/issue', [AdminInviteController::class, 'issueToAll']);

    Route::get('/waitlist', [AdminWaitlistController::class, 'apiIndex']);
    Route::post('/waitlist/{waitlistRequest}/admit', [AdminWaitlistController::class, 'admit']);
    Route::delete('/waitlist/{waitlistRequest}', [AdminWaitlistController::class, 'destroy']);

    Route::get('/interests', [AdminInterestController::class, 'apiIndex']);
    Route::post('/interests', [AdminInterestController::class, 'store']);
    Route::put('/interests/{interest}', [AdminInterestController::class, 'update']);
    Route::delete('/interests/{interest}', [AdminInterestController::class, 'destroy']);
    Route::get('/interest-requests', [AdminInterestController::class, 'apiRequestIndex']);
    Route::put('/interest-requests/{interestRequest}', [AdminInterestController::class, 'updateRequest']);
    Route::delete('/interest-requests/{interestRequest}', [AdminInterestController::class, 'destroyRequest']);
    Route::post('/interest-requests/{interestRequest}/approve', [AdminInterestController::class, 'approveRequest']);
    Route::post('/interest-requests/{interestRequest}/reject', [AdminInterestController::class, 'rejectRequest']);

    Route::get('/media', [AdminMediaController::class, 'apiIndex']);
    Route::get('/media-duplicates', [AdminMediaDuplicateController::class, 'apiIndex']);
    Route::post('/media/{media}/duplicate-review', [AdminMediaDuplicateController::class, 'queueReview']);
    Route::post('/media/{media}/moderate', [AdminMediaController::class, 'moderate']);

    Route::get('/reports', [AdminReportController::class, 'apiIndex']);
    Route::post('/reports/{report}/act', [AdminReportController::class, 'act']);

    Route::get('/deleted-content', [AdminDeletedContentController::class, 'apiIndex']);
    Route::post('/deleted-content/{type}/{id}/restore', [AdminDeletedContentController::class, 'restore']);
    Route::delete('/deleted-content/{type}/{id}', [AdminDeletedContentController::class, 'destroy']);

    Route::get('/posts', [AdminPostController::class, 'apiIndex']);
    Route::post('/posts/{post}/moderate', [AdminPostController::class, 'moderate']);
    Route::get('/post-comments', [AdminPostCommentController::class, 'apiIndex']);
    Route::post('/post-comments/{postComment}/moderate', [AdminPostCommentController::class, 'moderate']);

    Route::get('/stories', [AdminStoryController::class, 'apiIndex']);
    Route::post('/stories/{story}/moderate', [AdminStoryController::class, 'moderate']);

    Route::get('/pages', [AdminStaticPageController::class, 'apiIndex']);
    Route::post('/pages', [AdminStaticPageController::class, 'store']);
    Route::post('/pages/seed-defaults', [AdminStaticPageController::class, 'seedDefaults']);
    Route::put('/pages/{staticPage}', [AdminStaticPageController::class, 'update']);
});

Route::middleware(['auth', 'approved'])->prefix('api/interests')->group(function () {
    // Ratings target (user, character|null); character_id is passed in the query
    // (GET) or body (POST) so the same endpoints serve user and character profiles.
    Route::get('/', [InterestController::class, 'apiIndex']);
    Route::post('/ratings', [InterestController::class, 'batchRate']);
    Route::post('/inherit', [InterestController::class, 'setInheritance']);
    Route::post('/request', [InterestController::class, 'requestNew']);
});

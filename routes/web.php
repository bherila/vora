<?php

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\AdminInterestController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminPostCommentController;
use App\Http\Controllers\Admin\AdminPostController;
use App\Http\Controllers\Admin\AdminStaticPageController;
use App\Http\Controllers\Admin\AdminStoryController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\Follow\FollowController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostCommentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostReactionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\Story\AuthorshipInviteController;
use App\Http\Controllers\Story\StoryAuthorController;
use App\Http\Controllers\StoryController;
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
    Route::post('/api/account/deactivate', [ProfileController::class, 'deactivate']);
    Route::post('/api/account/delete', [ProfileController::class, 'destroy']);

    Route::get('/api/push-subscriptions', [PushSubscriptionController::class, 'status']);
    Route::post('/api/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/api/push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::get('/pending-approval', fn () => view('auth.pending-approval'))->name('approval.pending');
    Route::get('/user/settings', fn () => view('user.settings'))->name('user.settings');

    // Reachable while deactivated (exempt in EnsureNotDeactivated) so the user
    // can reactivate or sign out.
    Route::get('/account/deactivated', fn () => view('auth.deactivated'))->name('account.deactivated');
    Route::post('/account/reactivate', [ProfileController::class, 'reactivate'])->name('account.reactivate');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Authenticated + verified + approved app area
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
    Route::get('/characters', [CharacterController::class, 'page'])->name('characters');

    // Media library + shareable single-media view.
    Route::get('/media', [MediaController::class, 'library'])->name('media');
    Route::get('/m/{ulid}', [MediaController::class, 'viewPage'])->name('media.view');

    // Cross-user exploration of approved, discoverable media.
    Route::get('/explore', [ExploreController::class, 'page'])->name('explore');
    Route::get('/api/explore', [ExploreController::class, 'apiIndex']);
    Route::get('/api/explore/stories', [ExploreController::class, 'apiStories']);

    // Stories workspace + shareable single-story reader.
    Route::get('/stories', [StoryController::class, 'page'])->name('stories');
    Route::get('/s/{ulid}', [StoryController::class, 'readerPage'])->name('stories.view');

    Route::get('/users', [FollowController::class, 'directory'])->name('users.directory');
    Route::get('/users/follow-requests', [FollowController::class, 'inboxPage'])->name('users.follow-requests');
    Route::get('/users/{user}', [FollowController::class, 'profilePage'])->name('users.profile');

    Route::prefix('api/characters')->group(function () {
        Route::get('/', [CharacterController::class, 'index']);
        Route::post('/', [CharacterController::class, 'store']);
        Route::patch('/{character}', [CharacterController::class, 'update']);
        Route::delete('/{character}', [CharacterController::class, 'destroy']);
        Route::post('/{character}/profile-picture', [CharacterController::class, 'storeProfilePicture']);
        Route::post('/{character}/profile-picture/{media}/complete', [CharacterController::class, 'completeProfilePicture']);
        Route::delete('/{character}/profile-picture', [CharacterController::class, 'removeProfilePicture']);
    });

    Route::prefix('api/users')->group(function () {
        Route::get('/', [FollowController::class, 'users']);
        Route::get('/follow-requests/count', [FollowController::class, 'count']);
        Route::get('/follow-requests', [FollowController::class, 'inbox']);
        Route::post('/follow-requests/{followRequest}/accept', [FollowController::class, 'accept']);
        Route::post('/follow-requests/{followRequest}/decline', [FollowController::class, 'decline']);
        Route::get('/{user}', [FollowController::class, 'profile']);
        Route::post('/{user}/follow-requests', [FollowController::class, 'requestFollow']);
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
        Route::get('/by-ulid/{ulid}', [MediaController::class, 'showByUlid']);
        // HLS playback proxy: manifests served inline (rewritten), segments 302-redirected to R2.
        Route::get('/{media}/hls/{path?}', [MediaController::class, 'streamHls'])
            ->where('path', '.*')
            ->name('media.hls');
        Route::post('/{media}/complete', [MediaController::class, 'complete']);
        Route::get('/{media}', [MediaController::class, 'show']);
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
    Route::get('/audit-log', [AdminAuditController::class, 'index'])->name('audit-log');
    Route::get('/interests', [AdminInterestController::class, 'index'])->name('interests');
    Route::get('/media', [AdminMediaController::class, 'index'])->name('media');
    Route::get('/stories', [AdminStoryController::class, 'index'])->name('stories');
    Route::get('/pages', [AdminStaticPageController::class, 'index'])->name('pages');
});

// Admin JSON API — session-authenticated (web middleware), admin-gated. The
// admin-only ability already enforces the full access model (admin + approved +
// not disabled) and returns a clean 403 for JSON callers.
Route::middleware(['auth', 'approved', 'can:admin-only'])->prefix('api/admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'apiIndex']);
    Route::post('/users/{user}/approve', [AdminUserController::class, 'approve']);
    Route::patch('/users/{user}', [AdminUserController::class, 'update']);
    // Purge/restore also operate on soft-deleted users, so include trashed in the binding.
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->withTrashed();
    Route::post('/users/{user}/restore', [AdminUserController::class, 'restore'])->withTrashed();

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
    Route::post('/media/{media}/moderate', [AdminMediaController::class, 'moderate']);

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

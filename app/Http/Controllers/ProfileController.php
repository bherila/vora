<?php

namespace App\Http\Controllers;

use App\Enums\Audience;
use App\Enums\MediaPurpose;
use App\Enums\MediaType;
use App\Http\Requests\Profile\CompleteProfilePictureRequest;
use App\Http\Requests\Profile\StoreProfilePictureRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\Media;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\User;
use App\Services\Media\MediaResponseService;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploads,
        private readonly MediaResponseService $responder,
        private readonly MediaService $media,
    ) {}

    public function storeProfilePicture(StoreProfilePictureRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $result = $this->uploads->createPendingUpload(
            $user,
            MediaType::Photo,
            $request->validated('filename'),
            $request->validated('content_type'),
            'Profile picture',
            Audience::Everyone,
            [],
            false,
            null,
            MediaPurpose::ProfilePicture,
        );

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($result['media']),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
        ], 201);
    }

    public function completeProfilePicture(CompleteProfilePictureRequest $request, Media $media): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($media->user_id !== $user->id || ! $media->isProfilePicture()) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        if (! $this->uploads->completeUpload($media)) {
            return response()->json([
                'success' => false,
                'message' => 'Upload could not be verified — the file is missing or exceeds the size limit.',
            ], 422);
        }

        $previousMediaId = $user->profile_picture_media_id;
        $user->profile_picture_media_id = $media->id;
        $user->save();

        // Clean up the replaced avatar so it does not orphan its object/row.
        if ($previousMediaId !== null && $previousMediaId !== $media->id) {
            $previous = Media::query()->find($previousMediaId);
            if ($previous instanceof Media) {
                $this->media->deleteIfUnreferenced($previous);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->responder->item($media->refresh()),
        ]);
    }

    /**
     * Remove the current profile picture and delete its media if nothing else
     * references it.
     */
    public function removeProfilePicture(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $mediaId = $user->profile_picture_media_id;
        $user->profile_picture_media_id = null;
        $user->save();

        if ($mediaId !== null) {
            $media = Media::query()->find($mediaId);
            if ($media instanceof Media) {
                $this->media->deleteIfUnreferenced($media);
            }
        }

        return response()->json(['success' => true, 'message' => 'Profile picture removed.']);
    }

    /**
     * Self-service deactivation: hide the account from other users. The user can
     * still log in but is gated to the reactivate page until they reactivate.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $user->deactivated_at = now();
        $user->save();

        return response()->json(['success' => true, 'message' => 'Account deactivated.']);
    }

    /**
     * Reactivate a deactivated account. Served as a page form so it is reachable
     * from the deactivated gate.
     */
    public function reactivate(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDeactivated()) {
            $user->deactivated_at = null;
            $user->save();
        }

        return redirect('/');
    }

    /**
     * Self-service deletion. This is a soft delete: the user is logged out and
     * cannot recover the account themselves — only an admin can restore or
     * permanently delete it.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Protect the app from being left with no administrator who can restore
        // or manage accounts (mirrors the admin delete guards).
        if ($user->id === 1) {
            return response()->json(['success' => false, 'message' => 'The primary admin account cannot be deleted.'], 403);
        }

        if ($user->isAdmin()) {
            $otherActiveAdmins = User::query()
                ->whereKeyNot($user->id)
                ->whereNotNull('approved_at')
                ->whereNull('deactivated_at')
                ->where('is_disabled', false)
                ->where(fn ($q) => $q->where('is_admin', true)->orWhere('id', 1))
                ->count();

            if ($otherActiveAdmins === 0) {
                return response()->json(['success' => false, 'message' => 'You are the last administrator and cannot delete your account.'], 403);
            }
        }

        $user->delete();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'message' => 'Account deleted.']);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validated();
        $emailChanged = $user->email !== $data['email'];
        $nameChanged = $user->name !== $data['name'];
        $displayNameChanged = $user->display_name !== $data['display_name'];
        if ($nameChanged && $user->name_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your real name is locked and cannot be changed.',
            ], 403);
        }

        if ($emailChanged && $user->email_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your email is locked and cannot be changed.',
            ], 403);
        }

        if ($nameChanged) {
            $user->name = $data['name'];
        }
        if ($displayNameChanged) {
            $user->display_name = $data['display_name'];
        }
        if ($emailChanged) {
            $user->email = $data['email'];
        }
        $gender = array_key_exists('gender', $data) ? $data['gender'] : $user->gender;
        $genderOther = array_key_exists('gender_other', $data) ? $data['gender_other'] : $user->gender_other;
        $userType = array_key_exists('user_type', $data) ? $data['user_type'] : $user->user_type;
        $userTypeOther = array_key_exists('user_type_other', $data) ? $data['user_type_other'] : $user->user_type_other;

        $user->gender = $gender;
        $user->gender_other = $gender === 'other' ? $genderOther : null;
        $user->user_type = $userType;
        $user->user_type_other = $userType === 'other' ? $userTypeOther : null;
        if (array_key_exists('preferred_user_types', $data)) {
            $user->preferred_user_types = $data['preferred_user_types'];
        }
        if (array_key_exists('preferred_genders', $data)) {
            $user->preferred_genders = $data['preferred_genders'];
        }
        if (array_key_exists('profile_audience', $data)) {
            $user->profile_audience = Audience::from($data['profile_audience']);
        }
        if (array_key_exists('profile_audience', $data) || array_key_exists('audience_user_ids', $data)) {
            if ($user->profile_audience === Audience::SpecificPeople) {
                $this->syncProfileAudienceMembers($user, $request->audienceUserIds());
            } else {
                // Leaving the specific tier drops any stale grants, so switching
                // back later cannot silently re-enable old access.
                $user->profileAudienceMembers()->delete();
            }
        }
        foreach ([
            'notify_new_post',
            'notify_post_reaction',
            'notify_post_comment',
            'notify_follow_request',
            'notify_follow_accepted',
            'notify_co_author_invite',
            'notify_co_author_invite_accepted',
        ] as $pref) {
            if (array_key_exists($pref, $data)) {
                $user->{$pref} = (bool) $data[$pref];
            }
        }
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'success' => true,
            'message' => $emailChanged ? 'Account updated. Please verify your new email address.' : 'Account updated.',
            'data' => [
                'name' => $user->name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'gender' => $user->gender,
                'gender_other' => $user->gender_other,
                'user_type' => $user->user_type,
                'user_type_other' => $user->user_type_other,
                'profile_audience' => $user->profile_audience->value,
                'audience_user_ids' => $user->profileAudienceMembers()->pluck('user_id')->map('intval')->sort()->values()->all(),
                'preferred_user_types' => $user->preferred_user_types,
                'preferred_genders' => $user->preferred_genders,
                'notify_new_post' => (bool) $user->notify_new_post,
                'notify_post_reaction' => (bool) $user->notify_post_reaction,
                'notify_post_comment' => (bool) $user->notify_post_comment,
                'notify_follow_request' => (bool) $user->notify_follow_request,
                'notify_follow_accepted' => (bool) $user->notify_follow_accepted,
                'notify_co_author_invite' => (bool) $user->notify_co_author_invite,
                'notify_co_author_invite_accepted' => (bool) $user->notify_co_author_invite_accepted,
            ],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $user->load([
            'characters',
            'media.interests',
            'posts.attachments',
            'posts.audienceMembers',
            'stories.interests',
            'stories.involvements',
            'sentFollowRequests.recipient:id,display_name,name',
            'receivedFollowRequests.requester:id,display_name,name',
            'profileAudienceMembers',
            'interestRatings.interest:id,name',
        ]);

        $comments = PostComment::query()
            ->where('user_id', $user->id)
            ->with('post:id,ulid,body')
            ->latest()
            ->get();
        $reactions = PostReaction::query()
            ->where('user_id', $user->id)
            ->with('post:id,ulid,body')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'exported_at' => now()->toIso8601String(),
                'account' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'display_name' => $user->display_name,
                    'email' => $user->email,
                    'birth_date' => $user->birth_date?->toDateString(),
                    'gender' => $user->gender,
                    'gender_other' => $user->gender_other,
                    'user_type' => $user->user_type,
                    'user_type_other' => $user->user_type_other,
                    'profile_audience' => $user->profile_audience->value,
                    'profile_audience_user_ids' => $user->profileAudienceMembers->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'preferred_user_types' => $user->preferred_user_types,
                    'preferred_genders' => $user->preferred_genders,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'approved_at' => $user->approved_at?->toIso8601String(),
                    'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ],
                'notification_preferences' => [
                    'notify_new_post' => (bool) $user->notify_new_post,
                    'notify_post_reaction' => (bool) $user->notify_post_reaction,
                    'notify_post_comment' => (bool) $user->notify_post_comment,
                    'notify_follow_request' => (bool) $user->notify_follow_request,
                    'notify_follow_accepted' => (bool) $user->notify_follow_accepted,
                ],
                'characters' => $user->characters->map(fn ($character): array => [
                    'id' => $character->id,
                    'display_name' => $character->display_name,
                    'description' => $character->description,
                    'gender' => $character->gender,
                    'user_type' => $character->user_type,
                    'created_at' => $character->created_at?->toIso8601String(),
                ])->values(),
                'media' => $user->media->map(fn (Media $media): array => [
                    'id' => $media->id,
                    'ulid' => $media->ulid,
                    'title' => $media->title,
                    'type' => $media->type->value,
                    'purpose' => $media->purpose->value,
                    'audience' => $media->audience->value,
                    'discoverable' => (bool) $media->discoverable,
                    'interests' => $media->interests->pluck('name')->values(),
                    'created_at' => $media->created_at?->toIso8601String(),
                ])->values(),
                'stories' => $user->stories->map(fn ($story): array => [
                    'id' => $story->id,
                    'ulid' => $story->ulid,
                    'title' => $story->title,
                    'type' => $story->type->value,
                    'status' => $story->status->value,
                    'audience' => $story->audience->value,
                    'discoverable' => (bool) $story->discoverable,
                    'interests' => $story->interests->pluck('name')->values(),
                    'created_at' => $story->created_at?->toIso8601String(),
                ])->values(),
                'posts' => $user->posts->map(fn ($post): array => [
                    'id' => $post->id,
                    'ulid' => $post->ulid,
                    'body' => $post->body,
                    'audience' => $post->audience->value,
                    'discoverable' => (bool) $post->discoverable,
                    'character_id' => $post->character_id,
                    'audience_user_ids' => $post->audienceMembers->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'attachments' => $post->attachments->map(fn ($attachment): array => [
                        'type' => $attachment->attachable_type,
                        'id' => $attachment->attachable_id,
                    ])->values(),
                    'created_at' => $post->created_at?->toIso8601String(),
                ])->values(),
                'comments' => $comments->map(fn (PostComment $comment): array => [
                    'id' => $comment->id,
                    'post_ulid' => $comment->post?->ulid,
                    'parent_id' => $comment->parent_id,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ])->values(),
                'reactions' => $reactions->map(fn (PostReaction $reaction): array => [
                    'id' => $reaction->id,
                    'post_ulid' => $reaction->post?->ulid,
                    'type' => $reaction->type,
                    'created_at' => $reaction->created_at?->toIso8601String(),
                ])->values(),
                'follow_requests_sent' => $user->sentFollowRequests->map(fn ($follow): array => [
                    'recipient_id' => $follow->recipient_id,
                    'recipient_display_name' => $follow->recipient?->display_name ?? $follow->recipient?->name,
                    'status' => $follow->status,
                    'created_at' => $follow->created_at?->toIso8601String(),
                ])->values(),
                'follow_requests_received' => $user->receivedFollowRequests->map(fn ($follow): array => [
                    'requester_id' => $follow->requester_id,
                    'requester_display_name' => $follow->requester?->display_name ?? $follow->requester?->name,
                    'status' => $follow->status,
                    'created_at' => $follow->created_at?->toIso8601String(),
                ])->values(),
                'interest_ratings' => $user->interestRatings->map(fn ($rating): array => [
                    'interest_id' => $rating->interest_id,
                    'interest_name' => $rating->interest?->name,
                    'character_id' => $rating->character_id,
                    'level' => $rating->level,
                ])->values(),
                'notifications' => $user->notifications()->latest()->get()->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'type' => $notification->data['type'] ?? $notification->type,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * @param  list<int>  $userIds
     */
    private function syncProfileAudienceMembers(User $user, array $userIds): void
    {
        $target = array_values(array_unique(array_filter($userIds, fn (int $id): bool => $id !== $user->id)));
        $existing = $user->profileAudienceMembers()->pluck('user_id')->map('intval')->all();

        $removed = array_values(array_diff($existing, $target));
        if ($removed !== []) {
            $user->profileAudienceMembers()->whereIn('user_id', $removed)->delete();
        }

        foreach (array_values(array_diff($target, $existing)) as $userId) {
            $user->profileAudienceMembers()->create(['user_id' => $userId]);
        }
    }
}

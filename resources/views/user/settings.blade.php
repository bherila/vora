@extends('layouts.app')

@section('content')
  @php
    $__currentUser = auth()->user();
    $__settingsInitialData = [
      'name' => $__currentUser->name,
      'display_name' => $__currentUser->display_name,
      'birth_date' => $__currentUser->birth_date?->toDateString(),
      'email' => $__currentUser->email,
      'gender' => $__currentUser->gender,
      'gender_other' => $__currentUser->gender_other,
      'user_type' => $__currentUser->user_type,
      'user_type_other' => $__currentUser->user_type_other,
      'preferred_user_types' => $__currentUser->preferred_user_types ?? [],
      'preferred_genders' => $__currentUser->preferred_genders ?? [],
      'profile_audience' => $__currentUser->profile_audience?->value ?? 'everyone',
      'audience_user_ids' => $__currentUser->profileAudienceMembers()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
      'id_verified_at' => $__currentUser->id_verified_at?->toIso8601String(),
      'name_locked' => (bool) $__currentUser->name_locked,
      'email_locked' => (bool) $__currentUser->email_locked,
      'notify_new_post' => (bool) $__currentUser->notify_new_post,
      'notify_post_reaction' => (bool) $__currentUser->notify_post_reaction,
      'notify_post_comment' => (bool) $__currentUser->notify_post_comment,
      'notify_follow_request' => (bool) $__currentUser->notify_follow_request,
      'notify_follow_accepted' => (bool) $__currentUser->notify_follow_accepted,
      'web_push_public_key' => config('webpush.vapid.public_key'),
      'web_push_subscription_count' => $__currentUser->pushSubscriptions()->count(),
      // Mirrors the EnsureApproved gate on /api/interests so the interest panels
      // only render when the API will actually accept the request.
      'can_manage_interests' => (bool) (! $__currentUser->is_disabled && $__currentUser->hasVerifiedEmail() && $__currentUser->isApproved()),
    ];
  @endphp
  <script id="user-settings-initial-data" type="application/json" @cspNonce>
    @json($__settingsInitialData)
  </script>

  <div id="user-settings"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/user-settings.tsx'])
@endpush

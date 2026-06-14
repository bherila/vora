@extends('layouts.app')

@section('content')
  @php
    $__currentUser = auth()->user();
  @endphp
  <script id="user-settings-initial-data" type="application/json" @cspNonce>
    @json([
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
      'id_verified_at' => $__currentUser->id_verified_at?->toIso8601String(),
      'name_locked' => (bool) $__currentUser->name_locked,
      'email_locked' => (bool) $__currentUser->email_locked,
    ])
  </script>

  <div id="user-settings"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/user-settings.tsx'])
@endpush

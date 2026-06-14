@extends('layouts.app')

@section('content')
  @php
    $__currentUser = auth()->user();
  @endphp
  <script id="user-settings-initial-data" type="application/json" @cspNonce>
    @json([
      'name' => $__currentUser->name,
      'email' => $__currentUser->email,
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

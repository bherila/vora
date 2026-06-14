@extends('layouts.app')

@section('content')
  @php
    $__currentUser = auth()->user();
  @endphp
  <script id="user-settings-initial-data" type="application/json" @cspNonce>
    @json([
      'name' => $__currentUser->name,
      'email' => $__currentUser->email,
    ])
  </script>

  <div id="user-settings"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/user-settings.tsx'])
@endpush

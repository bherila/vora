@extends('layouts.app')

@section('content')
  <script id="follow-profile-data" type="application/json" @cspNonce>
    @json(['userId' => $profileUser->id])
  </script>
  <div id="follow-profile"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/follow-profile.tsx'])
@endpush

@extends('layouts.app')

@section('content')
  <script id="waitlist-initial-data" type="application/json" @cspNonce>
    @json($waitlistBootstrap)
  </script>
  <div id="request-invitation"></div>
@endsection

@push('scripts')
  @vite(['resources/js/request-invitation.tsx'])
@endpush

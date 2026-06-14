@extends('layouts.app')

@section('content')
  @php
    $__mediaInitialData = [
      'last_interest_ids' => array_values(array_map('intval', auth()->user()->last_media_interest_ids ?? [])),
    ];
  @endphp
  <script id="user-media-initial-data" type="application/json" @cspNonce>
    @json($__mediaInitialData)
  </script>

  <div id="user-media"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/media.tsx'])
@endpush

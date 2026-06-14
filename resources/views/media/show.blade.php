@extends('layouts.app')

@section('content')
  <script id="media-view-initial-data" type="application/json" @cspNonce>
    @json(['ulid' => $ulid])
  </script>

  <div id="media-view"></div>
@endsection

@push('scripts')
  @vite(['resources/js/media/view.tsx'])
@endpush

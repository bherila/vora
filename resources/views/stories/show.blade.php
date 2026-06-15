@extends('layouts.app')

@section('content')
  <script id="story-reader-data" type="application/json" @cspNonce>
    @json(['ulid' => $ulid])
  </script>

  <div id="story-reader"></div>
@endsection

@push('scripts')
  @vite(['resources/js/stories/reader.tsx'])
@endpush

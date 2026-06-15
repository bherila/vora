@extends('layouts.app')

@section('content')
  <script id="stories-initial-data" type="application/json" @cspNonce>
    @json(['currentUserId' => auth()->id()])
  </script>

  <div id="stories-app"></div>
@endsection

@push('scripts')
  @vite(['resources/js/stories/page.tsx'])
@endpush

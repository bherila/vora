@extends('layouts.app')

@section('content')
  <div id="feed"></div>
@endsection

@push('scripts')
  @vite(['resources/js/community/feed.tsx'])
@endpush

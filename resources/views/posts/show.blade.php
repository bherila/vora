@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-3xl px-4 py-8">
    <div id="post-view"></div>
  </div>
@endsection

@push('scripts')
  @vite(['resources/js/community/post-view.tsx'])
@endpush

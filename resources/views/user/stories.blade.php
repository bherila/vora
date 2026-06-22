@extends('layouts.app')

@section('content')
  @include('partials.create-tabs')
  <div id="stories-app"></div>
@endsection

@push('scripts')
  @vite(['resources/js/stories/page.tsx'])
@endpush

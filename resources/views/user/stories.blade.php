@extends('layouts.app')

@section('content')
  <div id="stories-app"></div>
@endsection

@push('scripts')
  @vite(['resources/js/stories/page.tsx'])
@endpush

@extends('layouts.app')

@section('content')
  <div id="story-reader"></div>
@endsection

@push('scripts')
  @vite(['resources/js/stories/reader.tsx'])
@endpush

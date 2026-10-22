@extends('layouts.app')

@section('content')
  <div id="events"></div>
@endsection

@push('scripts')
  @vite(['resources/js/events/page.tsx'])
@endpush

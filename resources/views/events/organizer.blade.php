@extends('layouts.app')

@section('content')
  <div id="event-organizer"></div>
@endsection

@push('scripts')
  @vite(['resources/js/events/organizer.tsx'])
@endpush

@extends('layouts.app')

@section('content')
  <div id="notifications-page"></div>
@endsection

@push('scripts')
  @vite(['resources/js/community/notifications-page.tsx'])
@endpush

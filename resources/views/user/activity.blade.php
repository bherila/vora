@extends('layouts.app')

@section('content')
  <div id="your-activity"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/activity.tsx'])
@endpush

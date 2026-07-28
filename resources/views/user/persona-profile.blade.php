@extends('layouts.app')

@section('content')
  <div id="persona-profile"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/persona-profile.tsx'])
@endpush

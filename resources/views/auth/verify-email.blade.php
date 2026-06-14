@extends('layouts.app')

@section('content')
  <div id="verify-email" data-signup-status="{{ request('signup_status') }}"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/verify-email.tsx'])
@endpush

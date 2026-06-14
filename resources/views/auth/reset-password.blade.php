@extends('layouts.app')

@section('content')
  <div id="reset-password" data-token="{{ $token }}" data-email="{{ request('email', '') }}"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/reset-password.tsx'])
@endpush

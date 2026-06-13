@extends('layouts.app')

@section('content')
  <div id="forgot-password"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/forgot-password.tsx'])
@endpush

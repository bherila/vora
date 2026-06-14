@extends('layouts.app')

@section('content')
  <div id="verify-email"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/verify-email.tsx'])
@endpush

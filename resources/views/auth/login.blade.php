@extends('layouts.app')

@section('content')
  <div id="login"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/login.tsx'])
@endpush

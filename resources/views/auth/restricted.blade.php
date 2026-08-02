@extends('layouts.app')

@section('content')
  <div id="restricted-account"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/restrictions.tsx'])
@endpush

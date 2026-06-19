@extends('layouts.app')

@section('content')
  <div id="register"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/register.tsx'])
@endpush

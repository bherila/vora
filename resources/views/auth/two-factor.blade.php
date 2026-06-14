@extends('layouts.app')

@section('content')
  <div id="two-factor" data-attempt-token="{{ $attemptToken }}" data-app-env="{{ app()->environment() }}"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/two-factor.tsx'])
@endpush

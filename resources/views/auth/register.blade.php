@extends('layouts.app')

@section('content')
  <script id="register-initial-data" type="application/json" @cspNonce>
    @json($registerBootstrap)
  </script>
  <div id="register"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/register.tsx'])
@endpush

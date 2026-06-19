@extends('layouts.app')

@section('content')
  <div id="user-invites"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/invites.tsx'])
@endpush

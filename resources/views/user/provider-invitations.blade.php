@extends('layouts.app')

@section('content')
  <div id="provider-invitations"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/provider-invitations.tsx'])
@endpush

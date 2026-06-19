@extends('layouts.app')

@section('content')
  <div id="admin-invites"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/invites.tsx'])
@endpush

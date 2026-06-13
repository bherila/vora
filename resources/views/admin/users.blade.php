@extends('layouts.app')

@section('content')
  <div id="admin-users"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/users.tsx'])
@endpush

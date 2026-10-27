@extends('layouts.app')

@section('content')
  <div id="admin-app-icon"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/app-icon.tsx'])
@endpush

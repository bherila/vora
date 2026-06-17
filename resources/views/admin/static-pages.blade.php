@extends('layouts.app')

@section('content')
  <div id="admin-static-pages"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/static-pages.tsx'])
@endpush

@extends('layouts.app')

@section('content')
  <div id="admin-reports"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/reports.tsx'])
@endpush

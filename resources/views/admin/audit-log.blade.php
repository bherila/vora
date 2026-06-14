@extends('layouts.app')

@section('content')
  <div id="admin-audit-log"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/audit-log.tsx'])
@endpush

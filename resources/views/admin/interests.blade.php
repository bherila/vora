@extends('layouts.app')

@section('content')
  <div id="admin-interests"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/interests.tsx'])
@endpush


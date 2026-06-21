@extends('layouts.app')

@section('content')
  <div id="admin-deleted-content"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/deleted-content.tsx'])
@endpush

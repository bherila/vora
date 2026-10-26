@extends('layouts.app')

@section('content')
  <div id="admin-mail-deliveries"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/mail-deliveries.tsx'])
@endpush

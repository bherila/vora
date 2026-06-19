@extends('layouts.app')

@section('content')
  <div id="admin-waitlist"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/waitlist.tsx'])
@endpush

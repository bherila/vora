@extends('layouts.app')

@section('content')
  <div id="admin-places"></div>
@endsection

@push('scripts')
  @vite(['resources/js/location/admin-places.tsx'])
@endpush

@extends('layouts.app')

@section('content')
  <div id="admin-media"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/media.tsx'])
@endpush

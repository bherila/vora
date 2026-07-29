@extends('layouts.app')

@section('content')
  <div id="admin-media-duplicates"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/media-duplicates.tsx'])
@endpush

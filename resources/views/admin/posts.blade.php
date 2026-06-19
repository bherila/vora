@extends('layouts.app')

@section('content')
  <div id="admin-posts"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/posts.tsx'])
@endpush

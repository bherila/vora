@extends('layouts.app')

@section('content')
  <div id="admin-stories"></div>
@endsection

@push('scripts')
  @vite(['resources/js/admin/stories.tsx'])
@endpush

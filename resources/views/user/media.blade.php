@extends('layouts.app')

@section('content')
  <div id="user-media"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/media.tsx'])
@endpush

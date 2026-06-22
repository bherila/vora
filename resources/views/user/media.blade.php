@extends('layouts.app')

@section('content')
  @include('partials.create-tabs')
  <div id="user-media"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/media.tsx'])
@endpush

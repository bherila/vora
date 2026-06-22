@extends('layouts.app')

@section('content')
  @include('partials.create-tabs')
  <div id="characters"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/characters.tsx'])
@endpush

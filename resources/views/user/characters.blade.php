@extends('layouts.app')

@section('content')
  <div id="characters"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/characters.tsx'])
@endpush

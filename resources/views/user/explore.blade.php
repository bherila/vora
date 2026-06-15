@extends('layouts.app')

@section('content')
  <div id="explore"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/explore.tsx'])
@endpush

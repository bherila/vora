@extends('layouts.app')

@section('content')
  <div id="user-interests"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/interests.tsx'])
@endpush


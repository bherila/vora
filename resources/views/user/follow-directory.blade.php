@extends('layouts.app')

@section('content')
  <div id="follow-directory"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/follow-directory.tsx'])
@endpush

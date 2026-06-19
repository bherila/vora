@extends('layouts.app')

@section('content')
  <div id="follow-profile"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/follow-profile.tsx'])
@endpush

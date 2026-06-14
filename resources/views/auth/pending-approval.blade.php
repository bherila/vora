@extends('layouts.app')

@section('content')
  <div id="pending-approval"></div>
@endsection

@push('scripts')
  @vite(['resources/js/auth/pending-approval.tsx'])
@endpush

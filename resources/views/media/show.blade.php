@extends('layouts.app')

@section('content')
  <div id="media-view"></div>
@endsection

@push('scripts')
  @vite(['resources/js/media/view.tsx'])
@endpush

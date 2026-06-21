@extends('layouts.app')

@section('content')
  @php
    $__user = auth()->user();
    $__name = $__user?->display_name ?: $__user?->name;
    $__links = [
      ['label' => 'Feed', 'description' => 'Posts from you and people you follow.', 'href' => route('feed', [], false)],
      ['label' => 'Explore', 'description' => 'Discover media and stories from the community.', 'href' => route('explore', [], false)],
      ['label' => 'Browse users', 'description' => 'Find people and send follow requests.', 'href' => route('users.directory', [], false)],
      ['label' => 'Media', 'description' => 'Upload and manage your photos and videos.', 'href' => route('media', [], false)],
      ['label' => 'Characters', 'description' => 'Create and edit your personas.', 'href' => route('characters', [], false)],
      ['label' => 'Stories', 'description' => 'Write and co-author interactive stories.', 'href' => route('stories', [], false)],
      ['label' => 'Settings', 'description' => 'Profile photo, interests, and preferences.', 'href' => route('user.settings', [], false)],
      ['label' => 'Follow requests', 'description' => 'Review who wants to follow you.', 'href' => route('users.follow-requests', [], false)],
    ];
  @endphp

  <div class="mx-auto max-w-4xl px-4 py-8">
    <h1 class="text-2xl font-bold">Welcome back{{ $__name ? ', '.$__name : '' }}</h1>
    <p class="mt-2 text-muted-foreground">Jump back into the community.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
      @foreach ($__links as $__link)
        <a
          href="{{ $__link['href'] }}"
          class="block rounded-lg border border-gray-200 p-5 transition-colors hover:bg-gray-50 dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]"
        >
          <span class="font-medium">{{ $__link['label'] }}</span>
          <span class="mt-1 block text-sm text-muted-foreground">{{ $__link['description'] }}</span>
        </a>
      @endforeach
    </div>
  </div>
@endsection

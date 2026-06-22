@php
  // Shared sub-navigation for the consolidated "Create" area. Media, Characters,
  // and Stories keep their own routes (so each tab is directly linkable) but are
  // presented as one destination in the top nav.
  $__createTabs = [
    ['label' => 'Media', 'href' => route('media', [], false), 'active' => request()->routeIs('media')],
    ['label' => 'Characters', 'href' => route('characters', [], false), 'active' => request()->routeIs('characters')],
    ['label' => 'Stories', 'href' => route('stories', [], false), 'active' => request()->routeIs('stories')],
  ];
@endphp

<div class="mx-auto max-w-5xl px-4 pt-6">
  <nav class="flex flex-wrap gap-1 border-b border-border" aria-label="Create">
    @foreach ($__createTabs as $__tab)
      <a
        href="{{ $__tab['href'] }}"
        @if ($__tab['active']) aria-current="page" @endif
        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors {{ $__tab['active']
          ? 'border-foreground text-foreground'
          : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground' }}"
      >
        {{ $__tab['label'] }}
      </a>
    @endforeach
  </nav>
</div>

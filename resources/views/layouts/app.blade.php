<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @viteReactRefresh
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @php
      $__appIcon = app(\App\Services\Branding\AppIcon::class);
    @endphp
    <link rel="manifest" href="{{ route('manifest', [], false) }}">
    <meta name="apple-mobile-web-app-title" content="{{ $__appIcon->homeScreenName() }}">
    @if ($__appIcon->version() !== null)
      <link rel="icon" type="image/png" sizes="32x32" href="{{ $__appIcon->url('favicon') }}">
      <link rel="apple-touch-icon" href="{{ $__appIcon->url('apple-touch-icon') }}">
    @endif
    <meta name="color-scheme" content="dark light">
    <script @cspNonce>
      (function() {
        try {
          var theme = localStorage.getItem('theme') || 'system';
          var d = document.documentElement;
          var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
          if (isDark) d.classList.add('dark'); else d.classList.remove('dark');
        } catch (e) { /* no-op */ }
      })();
    </script>
    @php
      $__enabled = fn (string $module): bool => \App\Support\InstanceFeatures::enabled($module);
      $__currentUser = auth()->user();
      $__isAuthenticated = ! is_null($__currentUser);
      $__restrictions = $__isAuthenticated && \Illuminate\Support\Facades\Schema::hasTable('user_restrictions')
        ? app(\App\Services\Moderation\RestrictionGate::class)->subjectPayload($__currentUser)
        : [];
      $__mediaResponder = $__isAuthenticated ? app(\App\Services\Media\MediaResponseService::class) : null;
      $__accountAvatarUrl = $__isAuthenticated
        ? \App\Support\UserPresenter::avatarUrl($__currentUser, $__mediaResponder, $__currentUser)
        : null;
      $__characters = $__isAuthenticated && $__enabled('personas')
        ? $__currentUser->characters()->with('profilePicture')->orderBy('display_name')->get()
        : collect();
      $__activeIdentityId = $__isAuthenticated && $__enabled('personas')
        ? app(\App\Support\ActiveIdentity::class)->id(request(), $__currentUser)
        : null;
      // A persona-free account gets no switcher data at all. Once the user opts
      // in, the human identity is the first choice followed by owned personas.
      $__identities = $__characters->isEmpty()
        ? []
        : collect([[
            'id' => null,
            'displayName' => $__currentUser->display_name ?: $__currentUser->name,
            'avatarUrl' => $__accountAvatarUrl,
          ]])->concat($__characters->map(fn ($character) => [
            'id' => $character->id,
            'displayName' => $character->display_name,
            'avatarUrl' => \App\Support\UserPresenter::pictureUrl($character->profilePicture, $__mediaResponder, $__currentUser),
          ]))->values()->all();
      // Use the same gate the admin routes enforce (admin flag PLUS approved and
      // able to log in), not the bare isAdmin() flag — otherwise a pending or
      // disabled admin would still receive the Admin menu and open-report count
      // here even though every admin route blocks them.
      $__isAdmin = \Illuminate\Support\Facades\Gate::allows('admin-only');
      // Mirror the follow-request inbox filter (requester still active) so the
      // badge never counts requests the inbox hides.
      // Persona audience edges are auto-accepted and never belong in the human
      // friendship inbox.
      $__followRequestCount = $__isAuthenticated && $__enabled('profiles') ? $__currentUser->receivedFollowRequests()->whereNull('recipient_character_id')->where('status', 'pending')->whereHas('requester', fn ($q) => $q->active())->count() : 0;
      // Mirror the invite-inbox filter (pendingForActiveOwner) so the badge count
      // never includes invites the inbox hides (owner since gone inactive).
      $__authorshipInviteCount = $__isAuthenticated && $__enabled('stories') ? $__currentUser->storyAuthorships()->pendingForActiveOwner()->count() : 0;
      $__requestCount = $__followRequestCount + $__authorshipInviteCount;
      $__chatUnreadCount = $__isAuthenticated && $__enabled('chat') && $__currentUser->isApproved() && $__currentUser->isActive()
        ? app(\App\Services\Chat\ChatInbox::class)->unreadCount($__currentUser)
        : 0;
      $__navItems = array_values(array_filter([
        // Guests see the marketing home; logged-in users land on the feed, so
        // "Home" would just be a redirect — drop it from the authed nav.
        $__isAuthenticated ? null : ['label' => 'Home', 'href' => route('home', [], false)],
        $__isAuthenticated && $__enabled('events') ? ['label' => 'Events', 'href' => '/events'] : null,
        $__isAuthenticated && $__enabled('events') ? ['label' => 'Attendees', 'href' => '/attendees'] : null,
        $__isAuthenticated && $__enabled('community') ? ['label' => 'Feed', 'href' => route('feed', [], false)] : null,
        $__isAuthenticated && $__enabled('chat') ? ['label' => 'Messages', 'href' => route('chat.index', [], false), 'badge' => $__chatUnreadCount] : null,
        // The profile (/me) hosts the user's media, stories, characters, posts,
        // and favorites.
        $__isAuthenticated && $__enabled('profiles') ? ['label' => 'Profile', 'href' => route('me', [], false)] : null,
        $__isAuthenticated && $__enabled('community') ? ['label' => 'Explore', 'href' => route('explore', [], false)] : null,
        // Media, characters, and stories are all created and managed on the
        // profile now, so the standalone "Create" nav entry is gone.
        $__isAuthenticated && $__enabled('profiles') ? ['label' => 'People', 'href' => route('users.directory', [], false)] : null,
        $__isAuthenticated && $__enabled('profiles') ? ['label' => 'Requests', 'href' => route('users.follow-requests', [], false), 'badge' => $__requestCount] : null,
      ]));
      // Open abuse reports awaiting review, shown as a count on the admin link.
      $__openReportCount = $__isAdmin ? \App\Models\Report::open()->count() : 0;
      $__adminMenu = $__isAdmin ? [
        'label' => 'Admin',
        'items' => array_values(array_filter([
          $__enabled('events') ? ['type' => 'link', 'label' => 'Events', 'href' => '/events'] : null,
          ['type' => 'link', 'label' => 'Users', 'href' => route('admin.users', [], false)],
          ['type' => 'link', 'label' => 'Invites & signups', 'href' => route('admin.invites', [], false)],
          \App\Services\Auth\SignIn::usesProvider() ? null : ['type' => 'link', 'label' => 'Invitation requests', 'href' => route('admin.waitlist', [], false)],
          $__enabled('interests') ? ['type' => 'link', 'label' => 'Interests', 'href' => route('admin.interests', [], false)] : null,
          ['type' => 'link', 'label' => 'Media review', 'href' => route('admin.media', [], false)],
          ['type' => 'link', 'label' => 'Duplicate clusters', 'href' => route('admin.media-duplicates', [], false)],
          ['type' => 'link', 'label' => 'Abuse reports'.($__openReportCount > 0 ? " ({$__openReportCount})" : ''), 'href' => route('admin.reports', [], false)],
          $__enabled('stories') ? ['type' => 'link', 'label' => 'Story review', 'href' => route('admin.stories', [], false)] : null,
          $__enabled('community') ? ['type' => 'link', 'label' => 'Posts review', 'href' => route('admin.posts', [], false)] : null,
          ['type' => 'link', 'label' => 'Deleted content', 'href' => route('admin.deleted-content', [], false)],
          ['type' => 'link', 'label' => 'Static pages', 'href' => route('admin.pages', [], false)],
          ['type' => 'link', 'label' => 'Email delivery', 'href' => route('admin.mail-deliveries', [], false)],
          ['type' => 'link', 'label' => 'App icon & name', 'href' => route('admin.app-icon', [], false)],
          ['type' => 'link', 'label' => 'Audit log', 'href' => route('admin.audit-log', [], false)],
        ])),
      ] : null;
      $__profileHref = $__enabled('profiles') ? route('me', [], false) : ($__enabled('events') && $__isAuthenticated ? '/attendees/'.$__currentUser->id : route('user.settings', [], false));
      $__accountMenu = $__isAuthenticated ? [
        'label' => $__currentUser->display_name ?: $__currentUser->name,
        'avatarUrl' => $__accountAvatarUrl,
        // The combined account/identity menu always exposes this profile destination.
        'profileHref' => $__profileHref,
        'items' => array_values(array_filter([
          empty($__identities) ? null : ['type' => 'link', 'label' => 'Profile', 'href' => route('me', [], false)],
          $__enabled('community') ? ['type' => 'link', 'label' => 'Your activity', 'href' => route('activity.index', [], false)] : null,
          $__enabled('library') ? ['type' => 'link', 'label' => $__enabled('events') ? 'My collection' : 'Library', 'href' => route('library', [], false)] : null,
          ['type' => 'link', 'label' => 'Settings', 'href' => route('user.settings', [], false)],
          // Under provider sign-in the identity provider admits people instead.
          \App\Services\Auth\SignIn::usesProvider() ? null : ['type' => 'link', 'label' => 'Invites', 'href' => route('user.invites', [], false)],
          ['type' => 'action', 'label' => 'Log out', 'action' => 'logout'],
        ])),
      ] : null;
      $__guestMenuItems = $__isAuthenticated ? [] : [
        ['label' => 'Log in', 'href' => route('login', [], false), 'variant' => 'link'],
        \App\Services\Auth\SignIn::usesProvider() ? null : ['label' => 'Sign up', 'href' => route('register', [], false), 'variant' => 'primary'],
      ];
      $__guestMenuItems = array_values(array_filter($__guestMenuItems));
      $__payload = ['agePolicy' => app(\App\Services\Auth\AgeEligibility::class)->policy(), 'instance' => ['profile' => config('instance.profile'), 'modules' => config('instance.modules'), 'home_path' => \App\Support\InstanceFeatures::homePath()], 'restrictions' => $__restrictions, 'navbar' => [
        'brand' => ['label' => config('app.name'), 'href' => route('home', [], false)],
        'authenticated' => $__isAuthenticated,
        'isAdmin' => $__isAdmin,
        'requestCount' => $__requestCount,
        'navItems' => $__navItems,
        'adminMenu' => $__adminMenu,
        'accountMenu' => $__accountMenu,
        'guestMenuItems' => $__guestMenuItems,
        'identities' => $__identities,
        'activeIdentityId' => $__activeIdentityId,
      ]] + ($initialData ?? []);
    @endphp
    <script id="initial-data" type="application/json" @cspNonce>{!! json_encode($__payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @vite(['resources/css/app.css', 'resources/js/navbar.tsx'])
    @stack('head')
    <script @cspNonce>(_=>{let a})()</script>
  </head>
  <body class="min-h-screen flex flex-col">
    <header class="site-header border-b border-gray-200 dark:border-[#3E3E3A]{{ empty($__identities) ? ' h-14' : '' }}">
      <div id="navbar"></div>
    </header>
    <div id="restriction-banners"></div>

    <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
      @yield('content')
    </main>

    <footer class="border-t border-gray-200 dark:border-[#3E3E3A] py-6 text-sm text-center text-gray-600 dark:text-[#A1A09A]">
      <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
        <span>© {{ date('Y') }} {{ config('app.name') }}</span>
        @foreach ($footerPages ?? [] as $footerPage)
          <a class="underline-offset-4 hover:underline" href="{{ $footerPage['url'] }}">{{ $footerPage['label'] }}</a>
        @endforeach
      </div>
    </footer>

    @stack('scripts')
  </body>
</html>

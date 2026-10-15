<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', 'ASCOT DRRM')</title>
    @include('layouts.partials.theme')
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Notched phones: keep content clear of the home indicator. */
        .safe-bottom { padding-bottom: calc(env(safe-area-inset-bottom) + 0.5rem); }
        .safe-top { padding-top: env(safe-area-inset-top); }
        input, select, textarea { font-size: 16px; }
        /* Phones: body text at 16px, easier to read in a hurry (UI audit). */
        #app-main .text-sm { font-size: 1rem; line-height: 1.5rem; }
        @keyframes drrms-fade-in-up { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        .drrms-page-in { animation: drrms-fade-in-up 180ms ease-out both; }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
@php
    $tabs = [
        ['label' => 'Home', 'route' => 'app.home', 'pattern' => 'app.home', 'icon' => 'home'],
        ['label' => 'Report', 'route' => 'app.report', 'pattern' => 'app.report*', 'icon' => 'add_box'],
        ['label' => 'Map', 'route' => 'app.map', 'pattern' => 'app.map', 'icon' => 'map'],
        ['label' => 'Alerts', 'route' => 'app.alerts', 'pattern' => 'app.alerts', 'icon' => 'notifications'],
        ['label' => 'Me', 'route' => 'app.me', 'pattern' => 'app.me', 'icon' => 'person'],
    ];
@endphp
<body class="min-h-screen bg-canvas text-gray-900 antialiased"
      @if (auth()->user()?->role === 'Reporter' && ! request()->routeIs('app.hotlines', 'app.welcome', 'app.login', 'app.register'))
          data-reporter-id="{{ auth()->id() }}" data-reporter-campus="{{ auth()->user()->campus_id }}"
          data-report-sync-url="{{ route('api.reporter.reports.sync') }}"
      @endif>
    <a href="#app-main" class="skip-link">Skip to content</a>

    {{-- Top bar: the app name, your campus's status, alerts. --}}
    <header class="safe-top sticky top-0 z-30 border-b border-gray-100 bg-white">
        <div class="mx-auto flex h-14 max-w-md items-center justify-between gap-3 px-4">
            <a href="{{ route('app.home') }}" class="flex min-h-[2.75rem] items-center gap-2">
                <img src="{{ asset('images/drrm-logo-256.png') }}" alt="" class="h-7 w-7">
                <span class="text-base font-bold tracking-tight">ASCOT<span class="text-accent-ink">-DRRM</span></span>
            </a>
            {{-- Alerts live in the tab bar and the theme under Me, so the top bar
                 stays empty on app screens. The sign-in screens have no tabs, so
                 the theme switch shows here instead. --}}
            @hasSection('no-tabs')
                <x-theme-toggle />
            @endif
        </div>
        {{-- Honest about what this is until Phase B connects real accounts. --}}
        @if (! app()->environment('production') && ! auth()->check() && ! request()->routeIs('app.register', 'app.login', 'app.welcome', 'app.hotlines'))
            <p class="bg-accent-soft px-4 py-1 text-center text-xs font-medium text-accent-ink" data-sample-data>Preview: sample data, nothing is sent yet</p>
        @endif
    </header>

    {{-- Public emergency hotlines stay available without a connection. --}}
    <div data-offline-banner hidden class="sticky top-14 z-20 flex items-center justify-center gap-2 bg-night-800 px-4 py-2 text-sm text-snow">
        <x-icon name="wifi_off" class="h-4 w-4" /> No signal. Emergency hotlines are available offline.
    </div>

    <main id="app-main" tabindex="-1" data-page-content class="drrms-page-in mx-auto max-w-md px-4 pb-28 pt-4 focus:outline-hidden">
        @yield('content')
    </main>

    {{-- Bottom tab bar (not on the sign-in screens: @section('no-tabs')). --}}
    @hasSection('no-tabs')
    @else
    <nav class="safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-gray-100 bg-white" aria-label="App">
        <ul class="mx-auto grid max-w-md grid-cols-5">
            @foreach ($tabs as $tab)
                @php
                    $active = request()->routeIs($tab['pattern']);
                    $badge = $tab['route'] === 'app.alerts' ? ($unread ?? 0) : 0;
                @endphp
                <li>
                    <a href="{{ route($tab['route']) }}" @if ($active) aria-current="page" @endif
                       @if ($badge) aria-label="{{ $tab['label'] }}, {{ $badge }} new" @endif
                       class="flex min-h-[3.5rem] flex-col items-center justify-center gap-0.5 text-xs font-medium {{ $active ? 'text-accent-ink' : 'text-gray-500 hover:text-gray-900' }}">
                        <span class="relative" @if($tab['route'] === 'app.alerts' && auth()->user()?->role === 'Reporter' && !request()->routeIs('app.hotlines', 'app.welcome')) data-notification-bell data-count-url="{{ route('api.reporter.notifications') }}" @endif>
                            <x-icon :name="$tab['icon']" :fill="$active" class="h-6 w-6" />
                            <span data-notification-count @if(!$badge) hidden style="display:none" @endif class="absolute -right-2.5 -top-1.5 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-red-600 px-1 text-xs font-bold leading-none text-snow" aria-hidden="true">{{ $badge }}</span>
                            <span data-notification-announcement class="sr-only" aria-live="polite"></span>
                        </span>
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
    @endif

    <script>
        // Offline service worker (public/sw.js) and the "no signal" banner.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function () {});
            });
        }
        (function () {
            var banner = document.querySelector('[data-offline-banner]');
            function update() { banner.hidden = navigator.onLine; }
            window.addEventListener('online', update);
            window.addEventListener('offline', update);
            update();
        })();
    </script>
    @stack('scripts')
    <x-toast />
    <x-session-guard />
</body>
</html>

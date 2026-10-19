<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ASCOT DRRMS')</title>
    @include('layouts.partials.theme')
    {{-- Installable staff app (Admin and coordinators); the students' app is layouts/mobile. --}}
    <link rel="manifest" href="{{ asset('manifest-staff.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="DRRMS Staff">
    <script>
        // Install as an app: Chrome and Edge offer a prompt (kept for the
        // sidebar's Install app button); iPhone and iPad use Share → Add to
        // Home Screen. The service worker (public/sw.js) never caches
        // signed-in pages; it only shows the offline page and hotlines.
        window.drrmsInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            window.drrmsInstallPrompt = e;
            document.dispatchEvent(new Event('drrms:installable'));
        });
        document.addEventListener('alpine:init', function () {
            Alpine.data('staffInstall', function () {
                return {
                    prompt: !! window.drrmsInstallPrompt,
                    installed: window.matchMedia('(display-mode: standalone)').matches,
                    ios: /iphone|ipad|ipod/i.test(navigator.userAgent),
                    init: function () {
                        var self = this;
                        document.addEventListener('drrms:installable', function () { self.prompt = true; });
                        window.addEventListener('appinstalled', function () { self.installed = true; self.prompt = false; });
                    },
                    install: function () {
                        var self = this, e = window.drrmsInstallPrompt;
                        if (! e) return;
                        e.prompt();
                        e.userChoice.then(function (choice) { if (choice.outcome === 'accepted') self.installed = true; self.prompt = false; window.drrmsInstallPrompt = null; });
                    },
                };
            });
        });
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () { navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function () {}); });
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Chart.js (~200 KB) only on pages that draw charts: they declare @section('charts'). --}}
    @hasSection('charts')
    <script src="{{ asset('vendor/chart.js/chart.umd.js') }}"></script>
    <script>
        // Short Chart.js entrance animation everywhere; none for reduced motion.
        // Axis text, gridlines and doughnut edges follow the theme (re-applied
        // when the theme is switched), so every chart reads on dark and light.
        if (window.Chart) {
            Chart.defaults.animation.duration = 180;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                Chart.defaults.animation = false;
            }
            var applyChartTheme = function () {
                var css = getComputedStyle(document.documentElement);
                Chart.defaults.color = css.getPropertyValue('--color-gray-500').trim() || '#6b7280';
                Chart.defaults.borderColor = css.getPropertyValue('--color-gray-100').trim() || '#f3f4f6';
                Chart.defaults.elements.arc.borderColor = css.getPropertyValue('--color-white').trim() || '#ffffff';
            };
            applyChartTheme();
            document.addEventListener('drrms:theme', function () {
                applyChartTheme();
                Object.values(Chart.instances).forEach(function (chart) { chart.update(); });
            });
        }
    </script>
    @endif
    <style>
        /* iOS Safari auto-zooms on focusing any input/select/textarea rendered
           smaller than 16px — this app's inputs use Tailwind's text-sm (14px)
           everywhere. One shared override here beats editing every form. */
        input, select, textarea { font-size: 16px; }

        /* Label/value rows (<dl><div class="flex justify-between">) on show,
           profile and account pages: long values such as email addresses must
           wrap inside the row instead of pushing the page wider than a phone. */
        dl > div.flex { gap: 1rem; }

        /* Sidebar: scrollable only when it genuinely overflows, with no visible track. */
        .sidebar-scroll { scrollbar-width: none; -ms-overflow-style: none; overscroll-behavior: contain; }
        .sidebar-scroll::-webkit-scrollbar { display: none; }
        dl > div.flex > dd { min-width: 0; text-align: right; overflow-wrap: anywhere; }

        /* The two seals: on the right of the page title from 640px up, in the
           phone top bar below that. --seal is the VISIBLE height of each
           circle: the PNGs pad their artwork differently (ASCOT's seal is 85.3%
           of its file height, DRRM's 71.0% with uneven side padding), so each
           image is scaled up and negative margins cancel the transparent
           padding. Both circles come out --seal tall and the flex gap is the
           real gap. */
        .app-seals { --seal: 64px; }
        .app-seals-bar { --seal: 36px; }
        .app-seal-ascot, .app-seal-drrm { max-width: none; flex-shrink: 0; }
        .app-seal-ascot {
            height: calc(var(--seal) / 0.853);
            margin: calc(var(--seal) * -0.0868);
        }
        .app-seal-drrm {
            height: calc(var(--seal) / 0.710);
            margin: calc(var(--seal) * -0.2028) calc(var(--seal) * -0.3612) calc(var(--seal) * -0.2056) calc(var(--seal) * -0.3195);
        }

        /* Phone tap targets: 44px. Table row actions and the backup panel's
           links are bare text links, so they get the height here. */
        @media (max-width: 639px) {
            a.inline-flex.rounded-full, button.rounded-full { min-height: 2.75rem; }
            td a, td button, td summary, [data-tap-row] a, [data-tap-row] button, [data-tap-row] summary {
                display: inline-flex; align-items: center; min-height: 2.75rem;
            }
        }

        

        /* ===================== Motion (all <= 300ms) ======================
           Only opacity/transform are animated, so nothing shifts layout or
           widens the page; translate is vertical only. */
        @keyframes drrms-fade-in-up { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @keyframes drrms-fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes drrms-fade-out { from { opacity: 1; } to { opacity: 0; } }
        @keyframes drrms-modal-in { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
        @keyframes drrms-modal-out { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateY(8px) scale(.98); } }
        @keyframes drrms-slide-down { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }

        /* Page content on load. */
        .drrms-page-in { animation: drrms-fade-in-up 180ms ease-out both; }

        /* List stagger: children of .drrms-stagger (card grids) and every
           table row in the main area. Delays cap at 105ms so the last row is
           in place by ~285ms. */
        .drrms-stagger > *, main table tbody tr { animation: drrms-fade-in-up 150ms ease-out both; }
        .drrms-stagger > :nth-child(2), main table tbody tr:nth-child(2) { animation-delay: 15ms; }
        .drrms-stagger > :nth-child(3), main table tbody tr:nth-child(3) { animation-delay: 30ms; }
        .drrms-stagger > :nth-child(4), main table tbody tr:nth-child(4) { animation-delay: 45ms; }
        .drrms-stagger > :nth-child(5), main table tbody tr:nth-child(5) { animation-delay: 60ms; }
        .drrms-stagger > :nth-child(6), main table tbody tr:nth-child(6) { animation-delay: 75ms; }
        .drrms-stagger > :nth-child(7), main table tbody tr:nth-child(7) { animation-delay: 90ms; }
        .drrms-stagger > :nth-child(n+8), main table tbody tr:nth-child(n+8) { animation-delay: 105ms; }

        /* Table rows: comfortable height, subtle hover. */
        main tbody tr { transition: background-color 150ms ease; }
        main tbody tr:not([data-empty-row]):hover { background-color: var(--color-gray-50); }

        /* Modals (confirm dialog, Generate Report) and the drawer overlay:
           the animation replays each time the element goes from display:none
           to visible; .is-closing plays the reverse before JS hides it. */
        [data-modal-backdrop]:not(.hidden), #sidebar-overlay:not(.hidden) { animation: drrms-fade-in 180ms ease-out both; }
        [data-modal-backdrop]:not(.hidden) [data-modal-panel] { animation: drrms-modal-in 180ms ease-out both; }
        [data-modal-backdrop].is-closing, #sidebar-overlay.is-closing { animation: drrms-fade-out 150ms ease-in forwards; }
        [data-modal-backdrop].is-closing [data-modal-panel] { animation: drrms-modal-out 150ms ease-in forwards; }

        /* Flash messages. */
        .drrms-flash { animation: drrms-slide-down 180ms ease-out both; }
        .drrms-flash.is-leaving { animation: drrms-fade-out 150ms ease-in forwards; }

        /* Hover/press feedback and a visible keyboard focus ring. */
        a, button, summary { transition-property: color, background-color, border-color, box-shadow, opacity, transform; transition-duration: 150ms; }
        .rounded-full:is(a, button):not(:disabled):active { transform: scale(.97); }
        a:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid var(--accent-line); outline-offset: 2px; }

        /* Required: honour the OS "reduce motion" setting everywhere. */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-delay: 0ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body class="bg-canvas text-gray-900 antialiased overflow-x-hidden">
    <a href="#main-content" class="skip-link">Skip to content</a>
    {{-- dvh, not vh: on phones 100vh runs under the browser's bottom bar and
         hid the drawer's Log out button. --}}
    <div class="flex h-dvh overflow-hidden">
        {{-- Backdrop for the mobile off-canvas drawer — hidden/inert until JS opens it. --}}
        <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-black/40 lg:hidden"></div>

        {{-- Sidebar (docs/PLAN.md §3, inspo 1): one surface with the page, a hairline
             edge, grouped sections, Material Symbols, the active item on an accent
             tint with an accent bar. Static from lg; an off-canvas drawer below. --}}
        <aside id="sidebar"
               class="sidebar-scroll fixed inset-y-0 left-0 z-50 flex h-dvh w-64 -translate-x-full flex-col overflow-y-auto border-r border-gray-100 bg-white text-gray-900 transition-transform duration-150 ease-out lg:static lg:z-auto lg:w-64 lg:shrink-0 lg:translate-x-0">
            <div class="px-5 pb-4 pt-5">
                {{-- Wordmark and the drawer's close button share one row as flex items,
                     so they can't overlap whatever the font. --}}
                <div class="flex items-center justify-between gap-2">
                    <span class="sidebar-wordmark block min-w-0 break-words text-[22px] font-bold leading-none tracking-tight text-gray-900 lg:text-2xl" data-sidebar-wordmark>ASCOT<span class="text-accent-ink">-DRRMS</span></span>
                    <button type="button" id="sidebar-close" class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 hover:text-gray-900 lg:hidden" aria-label="Close menu">
                        <x-icon name="close" />
                    </button>
                </div>
                <p class="mt-1 text-xs text-gray-500">Disaster Risk Reduction &amp; Management</p>

                @auth
                    @php
                        $nameParts = preg_split('/\s+/', trim(Auth::user()->full_name)) ?: [''];
                        $initials = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
                        // The uploaded photo if its file is there; otherwise the initials.
                        $userPhoto = Auth::user()->existingPhotoUrl();
                    @endphp
                    {{-- Phones: who is signed in. On desktop the top bar shows it. --}}
                    <div class="mt-4 flex items-center gap-3 rounded-xl border border-gray-100 bg-gray-50 p-3 lg:hidden" data-user-card>
                        @if ($userPhoto)
                            {{-- Decorative: the name is right beside it. Loaded straight away
                                 (no loading="lazy"): it's at the top of every page. --}}
                            <img src="{{ $userPhoto }}" alt="" width="36" height="36" class="h-9 w-9 shrink-0 rounded-full object-cover" data-user-photo>
                        @else
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent-ink" aria-hidden="true" data-user-initials>{{ $initials }}</span>
                        @endif
                        <span class="min-w-0">
                            <span class="block break-words text-sm font-semibold text-gray-900">{{ Auth::user()->full_name }}</span>
                            <span class="block break-words text-xs text-gray-500">{{ Auth::user()->role }}</span>
                        </span>
                    </div>
                @endauth
            </div>

            @auth
                @php
                    $role = Auth::user()->role;
                    $prefix = $role === 'DRRMO Admin' ? 'admin' : 'coordinator';

                    // Four groups of at most five (UI audit): Operations (what needs
                    // action now, most urgent first) / Prepare / Records /
                    // Administration (Admin only). Account sits under the avatar
                    // menu on desktop, so it shows in the sidebar on phones only.
                    // The phone-app consoles are sample data until Phase B and
                    // absent in production.
                    $hasConsoles = \Illuminate\Support\Facades\Route::has("{$prefix}.command.inbox");
                    $unreadNotices = \App\Models\StaffNotification::where('personnel_id', Auth::id())->whereNull('read_at')->count();
                    $navSections = array_filter([
                        'Operations' => array_values(array_filter([
                            ['label' => 'Dashboard', 'route' => "{$prefix}.dashboard", 'pattern' => "{$prefix}.dashboard", 'icon' => 'space_dashboard'],
                            $hasConsoles ? ['label' => 'SOS Console', 'route' => "{$prefix}.command.sos", 'pattern' => "{$prefix}.command.sos", 'icon' => 'sos', 'urgent' => true] : null,
                            $hasConsoles ? ['label' => 'Reports Inbox', 'route' => "{$prefix}.command.inbox", 'pattern' => "{$prefix}.command.inbox", 'icon' => 'inbox'] : null,
                            ['label' => 'Dispatch', 'route' => "{$prefix}.dispatch.index", 'pattern' => "{$prefix}.dispatch.*", 'icon' => 'directions_car'],
                            ['label' => 'Roll Call', 'route' => "{$prefix}.roll-calls.index", 'pattern' => "{$prefix}.roll-calls.*", 'icon' => 'checklist'],
                            ['label' => 'Notifications', 'route' => "{$prefix}.notifications.index", 'pattern' => "{$prefix}.notifications.*", 'icon' => 'notifications', 'badge' => $unreadNotices],
                            ['label' => 'Campus broadcasts', 'route' => "{$prefix}.broadcasts.index", 'pattern' => "{$prefix}.broadcasts.*", 'icon' => 'campaign'],
                            ['label' => 'Incidents', 'route' => "{$prefix}.incidents.index", 'pattern' => "{$prefix}.incidents.*", 'icon' => 'crisis_alert'],
                            $hasConsoles ? ['label' => 'Campus Status', 'route' => "{$prefix}.command.campus-status", 'pattern' => "{$prefix}.command.campus-status", 'icon' => 'shield'] : null,
                        ])),
                        'Prepare' => [
                            ['label' => 'Advisories', 'route' => "{$prefix}.advisories.index", 'pattern' => "{$prefix}.advisories.*", 'icon' => 'campaign'],
                            ['label' => 'Drills', 'route' => "{$prefix}.drills.index", 'pattern' => "{$prefix}.drills.*", 'icon' => 'fact_check'],
                            ['label' => 'Buildings/Areas', 'route' => "{$prefix}.buildings.index", 'pattern' => "{$prefix}.buildings.*", 'icon' => 'apartment'],
                            ['label' => 'Hotlines', 'route' => "{$prefix}.hotlines.index", 'pattern' => "{$prefix}.hotlines.*", 'icon' => 'call'],
                        ],
                        'Records' => [
                            ['label' => 'Consolidated Report', 'route' => 'reports.consolidated.index', 'pattern' => 'reports.consolidated.*', 'icon' => 'summarize'],
                            ['label' => 'Archive', 'route' => "{$prefix}.archive.index", 'pattern' => "{$prefix}.archive.*", 'icon' => 'archive'],
                            ...($role === 'Coordinator' ? [['label' => 'Reporter Validation', 'route' => 'coordinator.reporters.pending', 'pattern' => 'coordinator.reporters.*', 'icon' => 'manage_accounts']] : []),
                        ],
                        'Administration' => $role === 'DRRMO Admin' ? [
                            ['label' => 'User Management', 'route' => 'admin.personnel.index', 'pattern' => 'admin.personnel.*', 'icon' => 'manage_accounts'],
                            ['label' => 'Settings', 'route' => 'admin.settings.index', 'pattern' => 'admin.settings.*', 'icon' => 'settings'],
                            ['label' => 'Backup & Restore', 'route' => 'admin.backup.index', 'pattern' => 'admin.backup.*', 'icon' => 'database'],
                            ['label' => 'Activity Log', 'route' => 'admin.activity-log.index', 'pattern' => 'admin.activity-log.*', 'icon' => 'history'],
                        ] : [],
                        'Account' => [
                            ['label' => 'My Profile', 'route' => "{$prefix}.profile.show", 'pattern' => "{$prefix}.profile.*", 'icon' => 'person'],
                            // Coordinators: their own entries (the Admin's full log is under Administration).
                            ...($role === 'DRRMO Admin' ? [] : [
                                ['label' => 'My Activity Log', 'route' => 'coordinator.activity-log.index', 'pattern' => 'coordinator.activity-log.*', 'icon' => 'history'],
                            ]),
                            // What each module is for, the drill workflow and every status, in plain words.
                            ['label' => 'Help', 'route' => 'help', 'pattern' => 'help', 'icon' => 'help'],
                        ],
                    ]);
                @endphp

                <nav id="sidebar-nav" class="flex flex-1 flex-col gap-5 px-3 pb-5" aria-label="Main">
                    @foreach ($navSections as $section => $items)
                        <div @class(['lg:hidden' => $section === 'Account'])>
                            <p class="nav-section">{{ $section }}</p>
                            <ul class="space-y-0.5">
                                @foreach ($items as $item)
                                    @php $active = request()->routeIs($item['pattern']); @endphp
                                    <li>
                                        <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif class="nav-item">
                                            <x-icon :name="$item['icon']" :fill="$active" class="h-5 w-5 shrink-0 {{ $item['icon'] === 'crisis_alert' ? 'text-red-600' : '' }}" />
                                            <span class="min-w-0 flex-1 break-words leading-5">{{ $item['label'] }}</span>
                                            @if (! empty($item['badge']))
                                                <span class="flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold {{ ! empty($item['urgent']) ? 'bg-red-600 text-snow' : 'bg-gray-100 text-gray-700' }}" aria-label="{{ $item['badge'] }} new">{{ $item['badge'] }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    {{-- Install as an app: only when the browser offers it, or Safari on iPhone/iPad (see <head>). --}}
                    <div x-data="staffInstall" x-show="(prompt || ios) && ! installed" x-cloak class="border-t border-gray-100 pt-4" data-staff-install>
                        <button type="button" class="nav-item w-full text-left" x-show="prompt" x-on:click="install()">
                            <x-icon name="mobile_arrow_down" class="h-5 w-5 shrink-0" />
                            Install app
                        </button>
                        <p class="px-3 text-xs leading-5 text-gray-600" x-show="ios && ! prompt">Install: tap Share, then Add to Home Screen.</p>
                    </div>

                    {{-- Phones only: on desktop the theme switch and Log out are in the top bar. --}}
                    <div class="mt-auto space-y-0.5 border-t border-gray-100 pt-4 pb-[env(safe-area-inset-bottom)] lg:hidden">
                        <x-theme-toggle label />
                        {{-- The page's one logout form; the desktop avatar menu submits it too (form="logout-form"). --}}
                        <form id="logout-form" method="POST" action="{{ url('/logout') }}" data-no-loading data-confirm-form="You'll need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log out" data-confirm-focus="cancel">
                            @csrf
                            <button type="submit" class="nav-item w-full text-left">
                                <x-icon name="logout" />
                                Log out
                            </button>
                        </form>
                    </div>
                </nav>
            @endauth
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            {{-- Phone/tablet top bar (< lg): menu button, wordmark, seals. --}}
            <div class="relative flex h-14 items-center justify-between border-b border-gray-100 bg-white px-4 lg:hidden">
                <button type="button" id="sidebar-open" class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
                    <x-icon name="menu" class="h-6 w-6" />
                </button>
                <div class="ml-auto mr-2"><x-notification-bell :prefix="$prefix" :count="$unreadNotices" /></div>
                <span class="min-w-0 flex-1 truncate px-2 text-sm font-bold tracking-tight text-gray-900">ASCOT<span class="text-accent-ink">-DRRMS</span></span>
                {{-- Phones: the seals sit here. From 640px they move to the page title row. --}}
                <div class="app-seals app-seals-bar flex items-center gap-2 sm:hidden" data-app-seals="topbar">
                    <img src="{{ asset('images/ascot-logo-256.png') }}" alt="ASCOT Official Seal" class="app-seal-ascot">
                    <img src="{{ asset('images/drrm-logo-256.png') }}" alt="DRRM Office Logo" class="app-seal-drrm">
                </div>
            </div>

            {{-- Desktop top bar (lg+): scope and today's date on the left; help,
                 theme and the signed-in person on the right. --}}
            @auth
                <header class="hidden h-16 shrink-0 items-center justify-between gap-6 border-b border-gray-100 bg-white px-8 lg:flex" data-top-bar>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-gray-500">{{ Auth::user()->role === 'DRRMO Admin' ? 'All campuses' : (Auth::user()->campus->campus_name ?? 'Your campus') }}</p>
                        <p class="text-sm font-semibold text-gray-900">{{ now()->timezone('Asia/Manila')->format('l, F j, Y') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-notification-bell :prefix="$prefix" :count="$unreadNotices" />
                        <a href="{{ route('help') }}" class="icon-btn" aria-label="Help"><x-icon name="help" /></a>
                        <x-theme-toggle />
                        {{-- Avatar menu: the account links (the sidebar shows them on phones only). --}}
                        <div class="relative ml-2" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" data-account-menu>
                            <button type="button" class="flex items-center gap-3 rounded-lg py-1 pl-1 pr-2 hover:bg-gray-50 focus:outline-hidden focus-visible:ring-[3px] focus-visible:ring-accent-line" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" aria-controls="account-menu">
                                @if ($userPhoto)
                                    <img src="{{ $userPhoto }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                @else
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent-ink" aria-hidden="true">{{ $initials }}</span>
                                @endif
                                <span class="text-left">
                                    <span class="block text-sm font-semibold leading-5 text-gray-900">{{ Auth::user()->full_name }}</span>
                                    <span class="block text-xs leading-4 text-gray-500">{{ Auth::user()->role }}</span>
                                </span>
                                <x-icon name="keyboard_arrow_down" class="h-5 w-5 text-gray-500" />
                            </button>
                            <div id="account-menu" x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu"
                                 class="absolute right-0 z-50 mt-2 w-56 rounded-xl border border-gray-200 bg-white p-2">
                                <a href="{{ route($prefix . '.profile.show') }}" role="menuitem" class="nav-item"><x-icon name="person" class="h-5 w-5" /> My Profile</a>
                                @if ($role !== 'DRRMO Admin')
                                    <a href="{{ route('coordinator.activity-log.index') }}" role="menuitem" class="nav-item"><x-icon name="history" class="h-5 w-5" /> My Activity Log</a>
                                @endif
                                <div class="my-1 border-t border-gray-100"></div>
                                <button type="submit" form="logout-form" role="menuitem" class="nav-item w-full text-left"><x-icon name="logout" class="h-5 w-5" /> Log out</button>
                            </div>
                        </div>
                    </div>
                </header>
            @endauth

            <main id="main-content" tabindex="-1" class="flex-1 overflow-y-auto overflow-x-hidden bg-canvas p-4 focus:outline-hidden sm:p-6 lg:p-8">
                <div class="drrms-page-in" id="page-content">
                    @hasSection('breadcrumb')
                        <div class="mb-4 text-sm">
                            @yield('breadcrumb')
                        </div>
                    @endif

                    {{-- Page header. Title row: title (+ subtitle) left, the two seals right
                         (from 640px; on phones they are in the top bar). The page's actions
                         get their own row below, so buttons never compete with the title
                         for width. 24px to the content below. --}}
                    <div class="mb-6" data-page-header>
                        <div class="flex items-center justify-between gap-6" data-title-row>
                            <div class="min-w-0">
                                <h1 class="page-title">@yield('heading', 'ASCOT DRRMS')</h1>
                                @hasSection('subheading')
                                    <p class="page-subtitle">@yield('subheading')</p>
                                @endif
                            </div>
                            <div class="app-seals hidden shrink-0 items-center gap-4 sm:flex" data-app-seals="header">
                                <img src="{{ asset('images/ascot-logo-256.png') }}" alt="ASCOT Official Seal" class="app-seal-ascot">
                                <img src="{{ asset('images/drrm-logo-256.png') }}" alt="DRRM Office Logo" class="app-seal-drrm">
                            </div>
                        </div>
                        @hasSection('actions')
                            <div class="toolbar mt-4" data-page-actions>
                                @yield('actions')
                            </div>
                        @endif
                    </div>

                    @if (session('status'))
                        {{-- Success messages slide in and auto-dismiss; validation errors below stay put. --}}
                        <div class="drrms-flash mb-6 flex items-center gap-4 break-words rounded-xl border border-green-200 bg-green-50 px-4 text-sm text-green-800" role="status" data-flash data-auto-dismiss="{{ mb_strlen(session('status')) > 60 ? 12000 : 5000 }}">
                            <x-icon name="check_circle" fill class="h-5 w-5 shrink-0 text-green-600" />
                            <span class="min-w-0 flex-1 py-4">{{ session('status') }}</span>
                            <button type="button" data-flash-close class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-green-700 hover:bg-green-100" aria-label="Dismiss">
                                <x-icon name="close" class="h-4 w-4" />
                            </button>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="drrms-flash mb-6 break-words rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                            <ul class="list-disc pl-4">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    {{-- The one confirmation dialog (downloads, deletes, logout): see components/confirm-dialog. --}}
    <x-confirm-dialog />

    <script>
        // Shared motion helpers used by the drawer, the confirm dialog and the
        // Generate Report modal. Closing plays the .is-closing animation (150ms)
        // before hiding; with reduced motion it hides immediately.
        window.drrmsMotion = (function () {
            var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
            var CLOSE_MS = 150;

            function afterCloseAnimation(el, done) {
                if (reduced.matches) { done(); return; }
                el.classList.add('is-closing');
                setTimeout(function () {
                    el.classList.remove('is-closing');
                    done();
                }, CLOSE_MS);
            }

            return {
                reduced: function () { return reduced.matches; },
                afterCloseAnimation: afterCloseAnimation,
            };
        })();
    </script>

    <script>
        // Mobile/tablet (< lg) off-canvas sidebar drawer. At lg+ the sidebar is
        // always visible via CSS (lg:static lg:translate-x-0) and this script is
        // inert there since #sidebar-open/#sidebar-overlay are hidden by lg:hidden.
        // The drawer slides via its transform transition; the overlay fades.
        (function () {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebar-overlay');
            var openBtn = document.getElementById('sidebar-open');
            var closeBtn = document.getElementById('sidebar-close');
            var nav = document.getElementById('sidebar-nav');

            if (! sidebar || ! overlay || ! openBtn) {
                return;
            }

            function isOpen() {
                return ! sidebar.classList.contains('-translate-x-full');
            }

            function openDrawer() {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                openBtn.setAttribute('aria-expanded', 'true');
            }

            function closeDrawer() {
                if (! isOpen()) return;
                sidebar.classList.add('-translate-x-full');
                document.body.classList.remove('overflow-hidden');
                openBtn.setAttribute('aria-expanded', 'false');
                window.drrmsMotion.afterCloseAnimation(overlay, function () {
                    overlay.classList.add('hidden');
                });
            }

            openBtn.addEventListener('click', openDrawer);
            overlay.addEventListener('click', closeDrawer);
            if (closeBtn) {
                closeBtn.addEventListener('click', closeDrawer);
            }

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && isOpen()) closeDrawer();
            });

            if (nav) {
                nav.addEventListener('click', function (e) {
                    if (e.target.closest('a')) closeDrawer();
                });
            }

            // If the viewport is resized/rotated past the lg breakpoint while the
            // drawer is open, drop the scroll lock — the sidebar becomes static
            // there via CSS, so there's nothing left to "close", but the lock
            // would otherwise strand the page unscrollable.
            var mql = window.matchMedia('(min-width: 1024px)');
            var handleBreakpointChange = function (e) {
                if (e.matches) closeDrawer();
            };
            if (mql.addEventListener) {
                mql.addEventListener('change', handleBreakpointChange);
            } else if (mql.addListener) {
                mql.addListener(handleBreakpointChange);
            }
        })();
    </script>

    <script>
        // Submit loading state: on a real (not cancelled) POST form submission the
        // submit button is disabled and reads "Saving…"/"Deleting…" (no spinner —
        // nothing spins or bounces anywhere in the app),
        // which also blocks double submits. Registered after the confirm-dialog
        // handler, so a submission it (or an inline confirm()) cancelled is skipped.
        // GET forms (filters, report downloads) and [data-no-loading] forms are left alone.
        (function () {
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (e.defaultPrevented || form.hasAttribute('data-no-loading')) return;
                if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

                var button = e.submitter || form.querySelector('button[type="submit"]');
                if (! button || button.disabled) return;

                var method = form.querySelector('input[name="_method"]');
                var label = button.dataset.loadingText
                    || (method && method.value.toUpperCase() === 'DELETE' ? 'Deleting…' : 'Saving…');

                button.dataset.originalHtml = button.innerHTML;
                button.classList.add('inline-flex', 'items-center', 'justify-center', 'gap-4', 'cursor-wait');
                button.textContent = label;
                button.setAttribute('aria-busy', 'true');
                // Disable after the submit has been dispatched so the button's
                // own name/value (if any) is still included in the request.
                setTimeout(function () { button.disabled = true; }, 0);
            });

            // Coming back via the browser Back button restores the page from cache
            // with the button still disabled — put it back.
            window.addEventListener('pageshow', function (e) {
                if (! e.persisted) return;
                document.querySelectorAll('button[aria-busy="true"]').forEach(function (b) {
                    b.innerHTML = b.dataset.originalHtml;
                    b.disabled = false;
                    b.removeAttribute('aria-busy');
                    b.classList.remove('cursor-wait');
                });
            });
        })();
    </script>

    <script>
        // Flash messages: close button, and auto-dismiss for [data-auto-dismiss].
        (function () {
            function dismiss(flash) {
                if (! flash || flash.classList.contains('is-leaving')) return;
                if (window.drrmsMotion.reduced()) { flash.remove(); return; }
                flash.classList.add('is-leaving');
                setTimeout(function () { flash.remove(); }, 150);
            }

            document.querySelectorAll('[data-flash]').forEach(function (flash) {
                var close = flash.querySelector('[data-flash-close]');
                if (close) close.addEventListener('click', function () { dismiss(flash); });

                var ms = parseInt(flash.dataset.autoDismiss, 10);
                if (ms > 0) setTimeout(function () { dismiss(flash); }, ms);
            });
        })();
    </script>

    <script>
        // Info tips (components/info-tip): click/tap toggles and pins; mouse hover
        // previews; Escape or a click elsewhere closes, as does scrolling the
        // button off screen (otherwise the panel follows it). The
        // panel is fixed-positioned under (or above) its button and kept 16px
        // inside the viewport. While open it is moved to <body>: the page's
        // entrance animation leaves a transform on an ancestor, which would
        // otherwise make "fixed" relative to that ancestor. Its text is
        // re-inserted on open so screen readers announce it (role="status").
        (function () {
            var open = null;
            var pinned = false;
            var canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;

            function parts(tip) {
                if (! tip.drrmsPanel) tip.drrmsPanel = tip.querySelector('[data-info-tip-panel]');
                return { button: tip.querySelector('[data-info-tip-button]'), panel: tip.drrmsPanel };
            }

            function place(tip) {
                var p = parts(tip);
                var r = p.button.getBoundingClientRect();
                var w = p.panel.offsetWidth;
                var h = p.panel.offsetHeight;
                var left = Math.max(16, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 16));
                var top = r.bottom + 8;
                if (top + h > window.innerHeight - 8 && r.top - h - 8 > 8) top = r.top - h - 8;
                p.panel.style.left = left + 'px';
                p.panel.style.top = top + 'px';
            }

            function show(tip, pin) {
                if (open && open !== tip) hide(open);
                var p = parts(tip);
                if (open !== tip) {
                    var html = p.panel.innerHTML;
                    p.panel.innerHTML = '';
                    document.body.appendChild(p.panel);
                    p.panel.classList.remove('hidden');
                    p.panel.innerHTML = html;
                    place(tip);
                    p.button.setAttribute('aria-expanded', 'true');
                    open = tip;
                }
                pinned = pin;
            }

            function hide(tip) {
                var p = parts(tip);
                p.panel.classList.add('hidden');
                tip.appendChild(p.panel);
                p.button.setAttribute('aria-expanded', 'false');
                if (open === tip) { open = null; pinned = false; }
            }

            document.addEventListener('click', function (e) {
                var button = e.target.closest('[data-info-tip-button]');
                if (button) {
                    e.preventDefault();
                    var tip = button.closest('[data-info-tip]');
                    if (open === tip && pinned) hide(tip); else show(tip, true);
                    return;
                }
                if (open && ! e.target.closest('[data-info-tip-panel]')) hide(open);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && open) {
                    var button = parts(open).button;
                    hide(open);
                    button.focus();
                }
            });

            if (canHover) {
                document.addEventListener('mouseover', function (e) {
                    var tip = e.target.closest('[data-info-tip]');
                    if (tip && open !== tip && ! pinned) show(tip, false);
                });
                document.addEventListener('mouseout', function (e) {
                    var tip = e.target.closest('[data-info-tip]');
                    if (tip && open === tip && ! pinned && ! tip.contains(e.relatedTarget)) hide(tip);
                });
            }

            // Scrolling or resizing moves the panel with its button; once the
            // button leaves the screen, the panel closes.
            function follow() {
                if (! open) return;
                var r = parts(open).button.getBoundingClientRect();
                if (r.bottom < 0 || r.top > window.innerHeight || r.right < 0 || r.left > window.innerWidth) hide(open); else place(open);
            }
            window.addEventListener('scroll', follow, true);
            window.addEventListener('resize', follow);
        })();
    </script>

    <script>
        // KPI count-up: [data-count-up] elements holding a whole number count from
        // 0 to it over ~600ms. The real number is in the HTML from the start, so it
        // is correct with JS off; skipped for 0 and for reduced motion.
        (function () {
            if (window.drrmsMotion.reduced()) return;

            document.querySelectorAll('[data-count-up]').forEach(function (el) {
                var target = parseInt(el.textContent.trim(), 10);
                if (! target || target < 0 || String(target) !== el.textContent.trim()) return;

                var duration = 180;
                var start = null;
                el.textContent = '0';

                function step(now) {
                    if (start === null) start = now;
                    var t = Math.min(1, (now - start) / duration);
                    var eased = 1 - Math.pow(1 - t, 3);
                    el.textContent = String(Math.round(target * eased));
                    if (t < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        })();
    </script>

    <script>
        // Generic grid/table view toggle, driven by data-view-panel / data-view-content /
        // data-view-toggle attributes so any page can opt in without extra JS. Persists the
        // chosen view per group (e.g. "buildings", "inspections") in localStorage.
        (function () {
            function applyView(group, view) {
                document.querySelectorAll('[data-view-panel="' + group + '"] [data-view-content]').forEach(function (el) {
                    el.classList.toggle('hidden', el.dataset.viewContent !== view);
                });
                document.querySelectorAll('[data-view-toggle="' + group + '"]').forEach(function (btn) {
                    var active = btn.dataset.view === view;
                    btn.classList.toggle('bg-accent', active);
                    btn.classList.toggle('text-white', active);
                    btn.classList.toggle('text-gray-600', !active);
                });
            }

            function storageKey(group) {
                return 'ascot-drrms:view:' + group;
            }

            function initViewToggles() {
                document.querySelectorAll('[data-view-panel]').forEach(function (panel) {
                    var group = panel.dataset.viewPanel;
                    var stored = null;
                    try { stored = localStorage.getItem(storageKey(group)); } catch (e) {}
                    applyView(group, stored === 'table' ? 'table' : 'grid');
                });

                document.querySelectorAll('[data-view-toggle]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var group = btn.dataset.viewToggle;
                        var view = btn.dataset.view;
                        try { localStorage.setItem(storageKey(group), view); } catch (e) {}
                        applyView(group, view);
                    });
                });
            }

            document.addEventListener('DOMContentLoaded', initViewToggles);
        })();
    </script>

    @stack('scripts')
    <x-toast />
    <x-session-guard />
</body>
</html>

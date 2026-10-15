<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ASCOT DRRMS')</title>
    @include('layouts.partials.theme')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Phone tap targets: 44px. */
        @media (max-width: 639px) {
            .rounded-full.inline-flex, #mobile-menu .rounded-full {
                min-height: 2.75rem; display: inline-flex; align-items: center; justify-content: center;
            }
        }

        /* Motion (<= 300ms, opacity/vertical transform only). */
        @keyframes drrms-fade-in-up { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @keyframes drrms-fade-in { from { opacity: 0; } to { opacity: 1; } }
        .drrms-page-in { animation: drrms-fade-in-up 180ms ease-out both; }
        .drrms-stagger > * { animation: drrms-fade-in-up 180ms ease-out both; }
        .drrms-stagger > :nth-child(2) { animation-delay: 30ms; }
        .drrms-stagger > :nth-child(3) { animation-delay: 60ms; }
        .drrms-stagger > :nth-child(n+4) { animation-delay: 90ms; }
        #mobile-menu:not(.hidden) { animation: drrms-fade-in 150ms ease-out both; }
        a, button { transition-property: color, background-color, border-color, box-shadow, transform; transition-duration: 150ms; }
        .rounded-full:is(a, button):active { transform: scale(.97); }
        a:focus-visible, button:focus-visible { outline: 3px solid #16a34a; outline-offset: 2px; }

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
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <header class="sticky top-0 z-40 border-b border-gray-100 bg-white">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
            {{-- Wordmark only: the seals are shown once, in the hero. --}}
            <a href="{{ url('/') }}" class="flex min-h-[2.75rem] min-w-0 items-center">
                <span class="text-lg font-bold text-gray-900">ASCOT<span class="text-accent-ink">-DRRMS</span></span>
            </a>

            <nav class="hidden items-center gap-4 md:flex">
                <x-theme-toggle />
                @auth
                    <span class="max-w-[12rem] truncate text-sm text-gray-600">Hi, {{ Auth::user()->full_name }}</span>
                    <a href="{{ url(match (Auth::user()->role) { 'DRRMO Admin' => '/admin/dashboard', 'Reporter' => '/app', default => '/coordinator/dashboard' }) }}"
                       class="btn btn-primary">
                        Go to Dashboard
                    </a>
                    <form method="POST" action="{{ url('/logout') }}" data-confirm-form="You'll need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log out" data-confirm-focus="cancel">
                        @csrf
                        <button type="submit" class="btn btn-secondary">
                            Logout
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary">
                        Login
                    </a>
                    <a href="{{ route('register') }}" class="btn btn-primary">
                        Register
                    </a>
                @endauth
            </nav>

            <x-theme-toggle class="ml-auto md:hidden" />
            <button type="button" id="mobile-menu-toggle" class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-50 md:hidden" aria-label="Open menu" aria-expanded="false">
                <x-icon name="menu" class="h-6 w-6" />
                <x-icon name="close" class="hidden h-6 w-6" />
            </button>
        </div>

        <div id="mobile-menu" class="hidden border-t border-gray-100 px-4 py-4 sm:px-6 md:hidden">
            <div class="flex flex-col gap-4">
                @auth
                    <span class="truncate text-sm text-gray-600">Hi, {{ Auth::user()->full_name }}</span>
                    <a href="{{ url(match (Auth::user()->role) { 'DRRMO Admin' => '/admin/dashboard', 'Reporter' => '/app', default => '/coordinator/dashboard' }) }}"
                       class="btn btn-primary">
                        Go to Dashboard
                    </a>
                    <form method="POST" action="{{ url('/logout') }}" data-confirm-form="You'll need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log out" data-confirm-focus="cancel">
                        @csrf
                        <button type="submit" class="btn btn-secondary w-full">
                            Logout
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary text-center">
                        Login
                    </a>
                    <a href="{{ route('register') }}" class="btn btn-primary text-center">
                        Register
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <div id="main-content" tabindex="-1" class="focus:outline-hidden">
        @yield('content')
    </div>

    {{-- Logout confirmation (shared with layouts.app). --}}
    @auth
        <x-confirm-dialog />
    @endauth

    @stack('scripts')

    <script>
        (function () {
            var toggle = document.getElementById('mobile-menu-toggle');
            var menu = document.getElementById('mobile-menu');
            var iconOpen = document.getElementById('mobile-menu-icon-open');
            var iconClose = document.getElementById('mobile-menu-icon-close');

            toggle.addEventListener('click', function () {
                var isOpen = !menu.classList.contains('hidden');
                menu.classList.toggle('hidden');
                iconOpen.classList.toggle('hidden');
                iconClose.classList.toggle('hidden');
                toggle.setAttribute('aria-expanded', String(!isOpen));
            });
        })();
    </script>
    <x-toast />
    <x-session-guard />
</body>
</html>

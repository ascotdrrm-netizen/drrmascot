<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ASCOT DRRMS')</title>
    {{-- The background is the first thing anyone sees: start fetching it with the page. --}}
    <link rel="preload" as="image" href="{{ asset('images/campus-bg.jpg') }}" fetchpriority="high">
    @include('layouts.partials.theme')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* ================= Campus photo background (decorative) =================
           Two layers behind all content, pinned to the viewport with
           position: fixed (so a tall form like Register scrolls OVER the photo).
           The image itself uses background-attachment: scroll — NOT "fixed",
           which iOS Safari doesn't support. Only these layers are dimmed; the
           content's own opacity is never touched.
             1. ::before  the photo, cover + centred crop, slightly blurred (the
                source is 739x415, so the blur also hides upscaling softness);
                scaled 1.06 so the blurred edges never show.
             2. ::after   charcoal wash, 78% opacity (#0b0b0c), the dark theme canvas.
           html's colour is the fallback if the image fails to load. */
        html { background-color: #0b0b0c; }
        body.auth-bg { background: transparent; }
        body.auth-bg::before,
        body.auth-bg::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
        }
        body.auth-bg::before {
            background-image: url("{{ asset('images/campus-bg.jpg') }}");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: scroll;
            filter: blur(3px);
            transform: scale(1.06);
        }
        body.auth-bg::after {
            background-color: rgba(11, 11, 12, 0.78);
        }
        /* Text sitting directly on the photo (heading above the card). */
        .auth-on-photo { color: #fff; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.55); }

        /* ===== Institutional seals, centred above the heading =====
           --seal is the VISIBLE height of each circle. The PNGs pad their
           artwork differently (ASCOT's seal is 85.3% of its file height,
           DRRM's 71.0%, with uneven side padding), so each image is scaled
           and given negative margins that cancel its transparent padding:
           both circles come out exactly --seal tall, centred on one line,
           and the flex gap is the true gap between the visible seals.
           A soft drop-shadow follows each round shape (no box). */
        /* Phones 80px, tablets/desktops 88px; 80px on short laptop screens and
           64px on very short ones, so the card stays on screen. */
        .auth-seals { --seal: 80px; }
        @media (min-width: 640px) { .auth-seals { --seal: 88px; } }
        @media (min-width: 640px) and (max-height: 700px) { .auth-seals { --seal: 80px; } }
        @media (max-height: 600px) { .auth-seals { --seal: 64px; } }
        .auth-seal { max-width: none; filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.45)); }
        .auth-seal-ascot {
            height: calc(var(--seal) / 0.853);
            margin: calc(var(--seal) * -0.0868);
        }
        .auth-seal-drrm {
            height: calc(var(--seal) / 0.710);
            margin: calc(var(--seal) * -0.2028) calc(var(--seal) * -0.3612) calc(var(--seal) * -0.2056) calc(var(--seal) * -0.3195);
        }

        /* iOS Safari auto-zooms on focusing any input/select/textarea rendered
           smaller than 16px — this app's inputs use Tailwind's text-sm (14px)
           everywhere. One shared override here beats editing every form. */
        input, select, textarea { font-size: 16px; }

        /* Phone tap targets (~40px): the pill submit buttons are py-2 (36px). */
        @media (max-width: 639px) {
            button.rounded-full { min-height: 2.5rem; }
        }

        /* Auth pages: a simple fade only — no slides, stagger or spinners. */
        @keyframes drrms-fade-in { from { opacity: 0; } to { opacity: 1; } }
        .drrms-fade-in { animation: drrms-fade-in 250ms ease-out both; }
        a, button { transition-property: color, background-color, border-color, box-shadow; transition-duration: 150ms; }
        a:focus-visible, button:focus-visible { outline: 2px solid #16a34a; outline-offset: 2px; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-delay: 0ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="auth-bg min-h-screen antialiased overflow-x-hidden">
    {{--
        Below sm, "Back to Home" and the logos sit in normal document flow as a
        compact top bar instead of being `fixed` — on a short/narrow phone a
        fixed corner element can overlap the top of a tall centered form (e.g.
        Register) instead of just floating harmlessly beside it.
    --}}
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 bg-white px-4 py-3 sm:hidden">
        <a href="{{ url('/') }}" class="link-muted inline-flex items-center gap-1 text-sm">
            <x-icon name="arrow_back" class="h-4 w-4" />
            Back to Home
        </a>
    </div>

    {{-- Over the photo, "Back to Home" gets a white backing (text). The seals sit above the heading, below. --}}
    <div class="hidden sm:block">
        <div class="fixed left-4 top-4">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-1 rounded-full bg-white/95 px-3 py-1.5 text-sm font-medium text-gray-700 shadow-md hover:bg-white hover:text-gray-900">
                <x-icon name="arrow_back" class="h-4 w-4" />
                Back to Home
            </a>
        </div>
    </div>

    <div class="flex min-h-[calc(100vh-57px)] items-center justify-center p-4 sm:min-h-screen">
        <div class="drrms-fade-in w-full max-w-md">
            {{-- The institution's marks, centred above the name: same visible height,
                 side by side at every width (see .auth-seals in the styles above). --}}
            <div class="auth-seals mb-4 flex items-center justify-center gap-4 sm:mb-5 sm:gap-5" data-auth-seals>
                <img src="{{ asset('images/ascot-logo-256.png') }}" alt="ASCOT Official Seal" class="auth-seal auth-seal-ascot">
                <img src="{{ asset('images/drrm-logo-256.png') }}" alt="DRRM Office Logo" class="auth-seal auth-seal-drrm">
            </div>

            <div class="auth-on-photo mb-6 text-center sm:mb-8">
                <span class="text-2xl font-bold">ASCOT<span class="text-[#fb923c]">-DRRMS</span></span>
                <p class="mt-1 text-sm text-snow/80">Disaster Risk Reduction & Management System</p>
            </div>

            {{-- Solid white card: labels, inputs and errors keep full contrast. --}}
            <div class="rounded-xl border border-gray-100 bg-white p-6 sm:p-8">
                @if (session('status'))
                    <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </div>
        </div>
    </div>

    <script>
        // Prevent double submits on login/register/password forms: disable the
        // button and swap its text. No spinner here (auth pages: fades only).
        // A data-ajax form is left to resources/js/modules/ajax.js, which keeps
        // its own busy state: changing the text here first made it "restore"
        // the button to "Please wait…" after an error, stuck there.
        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented || e.target.matches('form[data-ajax]')) return;
            var button = e.submitter || e.target.querySelector('button[type="submit"]');
            if (! button || button.disabled) return;
            button.dataset.originalText = button.textContent;
            button.textContent = 'Please wait…';
            button.setAttribute('aria-busy', 'true');
            setTimeout(function () { button.disabled = true; }, 0);
        });

        window.addEventListener('pageshow', function (e) {
            if (! e.persisted) return;
            document.querySelectorAll('button[aria-busy="true"]').forEach(function (b) {
                b.textContent = b.dataset.originalText;
                b.disabled = false;
                b.removeAttribute('aria-busy');
            });
        });
    </script>

    @stack('scripts')
    <x-toast />
</body>
</html>

@extends('layouts.public')

@section('title', 'ASCOT DRRMS - Disaster Risk Reduction and Management System')

@php
    $hotlineGroups = \App\Support\Hotlines::groups();
    // "0917-152-9432" -> "tel:+639171529432" (a Philippine mobile number in international form).
    $telHref = function (string $number): string {
        $digits = preg_replace('/\D/', '', $number);

        return 'tel:' . (strlen($digits) === 11 && str_starts_with($digits, '0') ? '+63' . substr($digits, 1) : $digits);
    };
    // The number to call first, from the same hotline list as below.
    $firstNumber = function (string $label) use ($hotlineGroups): ?string {
        foreach ($hotlineGroups as $group) {
            foreach ($group['entries'] as $entry) {
                if ($entry['label'] === $label && ! empty($entry['numbers'])) {
                    return $entry['numbers'][0];
                }
            }
        }

        return null;
    };
    $drrmoNumber = $firstNumber('ASCOT DRRMO Office');
    $hasApp = \Illuminate\Support\Facades\Route::has('app.home');
@endphp

{{--
    Order (UI audit, F-pattern): a visitor in trouble first sees who we are,
    ONE red Call button and each campus's status; then what to do, the
    hotlines, the phone app, and last the office and what the system covers.
    Orange is kept for things you can tap; decorative icons are gray.
--}}
@section('content')
    {{-- ================= Hero: who we are, call now, the situation right now ================= --}}
    <section class="border-b border-gray-100">
        <div class="drrms-page-in mx-auto grid max-w-6xl items-center gap-8 px-4 py-8 sm:px-6 sm:py-12 lg:grid-cols-[1.1fr_1fr] lg:gap-12 lg:py-16">
            <div>
                {{-- The two seals, trimmed: --seal is the VISIBLE height of each circle.
                     The PNGs pad their artwork differently (ASCOT's seal is 85.3% of its
                     file height, DRRM's 71.0% with uneven side padding), so each image is
                     scaled up and negative margins cancel the padding; both circles come
                     out exactly --seal tall. Defined here, before the images. --}}
                <style>
                    .landing-seals { --seal: 80px; }
                    @media (min-width: 640px) { .landing-seals { --seal: 96px; } }
                    @media (min-width: 1024px) { .landing-seals { --seal: 112px; } }
                    .landing-seal-ascot, .landing-seal-drrm { max-width: none; flex-shrink: 0; }
                    .landing-seal-ascot {
                        height: calc(var(--seal) / 0.853);
                        margin: calc(var(--seal) * -0.0868);
                    }
                    .landing-seal-drrm {
                        height: calc(var(--seal) / 0.710);
                        margin: calc(var(--seal) * -0.2028) calc(var(--seal) * -0.3612) calc(var(--seal) * -0.2056) calc(var(--seal) * -0.3195);
                    }
                </style>
                <div class="landing-seals mb-6 flex items-center gap-6 sm:mb-8" data-landing-seals>
                    <img src="{{ asset('images/ascot-logo-256.png') }}" alt="ASCOT Official Seal" class="landing-seal-ascot">
                    <img src="{{ asset('images/drrm-logo-256.png') }}" alt="DRRM Office Logo" class="landing-seal-drrm">
                </div>

                <p class="eyebrow">Aurora State College of Technology · DRRM Office</p>
                <h1 class="mt-2 break-words text-3xl font-semibold leading-tight tracking-tight text-gray-900 sm:text-5xl sm:leading-[1.08]">
                    ASCOT Disaster Risk Reduction and Management System
                </h1>
                <p class="mt-4 hidden max-w-xl text-base leading-7 text-gray-600 sm:block">
                    Safety information, advisories and emergency help for the Zabali, Bazal and Casiguran
                    campuses, from the ASCOT DRRM Office.
                </p>

                {{-- One main action: call. Red because it is the emergency action. --}}
                <div class="mt-6 flex flex-col gap-4 sm:mt-8 sm:flex-row sm:flex-wrap sm:items-center" data-call-strip>
                    @if ($drrmoNumber)
                        <a href="{{ $telHref($drrmoNumber) }}" class="btn btn-danger-solid btn-lg w-full sm:w-auto" data-call-first>
                            <x-icon name="call" /> Call ASCOT DRRMO · {{ $drrmoNumber }}
                        </a>
                    @endif
                    @if ($hasApp)
                        <a href="{{ route('app.welcome') }}" class="btn btn-secondary btn-lg w-full sm:w-auto"><x-icon name="mobile_arrow_down" /> Get the phone app</a>
                    @endif
                </div>
                <p class="mt-4 text-sm text-gray-600">
                    <a href="#hotlines" class="link">All emergency hotlines</a>
                    <span class="px-2 text-gray-400" aria-hidden="true">·</span>
                    <a href="#guidance" class="link">What to do in an emergency</a>
                </p>
            </div>

            {{-- Each campus right now, from the advisories the DRRM Office has in effect. --}}
            <div class="rounded-xl border border-gray-100 bg-white" data-campus-status-panel>
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-4 sm:px-6">
                    <h2 class="eyebrow">Campus status now</h2>
                    <p class="text-xs text-gray-600">{{ now()->timezone('Asia/Manila')->format('M j, g:i A') }}</p>
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($campusStatus as $row)
                        @php $advisory = $row['advisories']->first(); @endphp
                        <li class="flex items-start gap-4 px-4 py-4 sm:px-6" data-campus-status-row>
                            @if ($row['status'] !== 'normal')
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ in_array($row['status'], ['suspended', 'evacuate']) ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' }}"><x-icon name="warning" fill /></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">{{ str_replace('ASCOT ', '', $row['campus']) }}</p>
                                    <p class="text-sm font-medium {{ in_array($row['status'], ['suspended', 'evacuate']) ? 'text-red-700' : 'text-amber-700' }}">{{ ['advisory' => 'Advisory in effect', 'suspended' => 'Classes suspended', 'evacuate' => 'Evacuate'][$row['status']] ?? 'Campus status' }}</p>
                                    <p class="text-xs leading-5 text-gray-600">{{ $row['detail'] }}</p>
                                </div>
                            @elseif ($advisory)
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700"><x-icon name="warning" fill /></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">{{ str_replace("ASCOT ", "", $row["campus"]) }}</p>
                                    <p class="text-sm font-medium text-amber-700">Advisory in effect: {{ $advisory->hazard_label }}{{ $advisory->headline_measurement ? ' — ' . $advisory->headline_measurement : '' }}</p>
                                    <p class="text-xs leading-5 text-gray-600">{{ $advisory->headline }}@if ($row['advisories']->count() > 1) · and {{ $row['advisories']->count() - 1 }} more @endif</p>
                                </div>
                            @else
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700"><x-icon name="check_circle" fill /></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">{{ str_replace("ASCOT ", "", $row["campus"]) }}</p>
                                    <p class="text-sm text-gray-600">No advisory in effect</p>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-600 sm:px-6">Advisories are posted by the ASCOT DRRM Office from official bulletins.</p>
            </div>
        </div>
    </section>

    {{-- ================= What to do when it happens ================= --}}
    @php
        $guidance = [
            ['icon' => 'cyclone', 'hazard' => 'Typhoon', 'steps' => ['Watch for advisories and class-suspension notices from the DRRM Office.', 'Charge your phone, secure loose objects, and keep a go-bag ready.', 'Stay indoors and away from windows while the strong winds pass.']],
            ['icon' => 'earthquake', 'hazard' => 'Earthquake', 'steps' => ['Duck, cover and hold on until the shaking stops.', 'Leave by the stairs once it stops, never the elevator.', 'Go to your assembly area and wait for the headcount.']],
            ['icon' => 'local_fire_department', 'hazard' => 'Fire', 'steps' => ['Raise the alarm and tell everyone nearby.', 'Leave by the nearest exit; stay low under smoke.', 'Never go back inside. Call the BFP and campus security.']],
            ['icon' => 'flood', 'hazard' => 'Flood or landslide', 'steps' => ['Move to higher ground; do not cross flowing water.', 'Stay off slopes and the Ermita Hill road after heavy rain.', 'Switch off power if water starts coming inside.']],
        ];
    @endphp
    <section id="guidance" class="scroll-mt-16 border-b border-gray-100 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-16">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">What to do when it happens</h2>
                <p class="mt-2 text-base text-gray-600">The first three things to remember. Your campus coordinator and the drills cover the rest.</p>
            </div>
            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($guidance as $item)
                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-6">
                        <h3 class="flex items-center gap-2 text-base font-semibold text-gray-900"><x-icon :name="$item['icon']" class="h-6 w-6 text-gray-700" /> {{ $item['hazard'] }}</h3>
                        <ol class="mt-4 space-y-4 text-sm leading-6 text-gray-700">
                            @foreach ($item['steps'] as $i => $step)
                                <li class="flex gap-4"><span class="font-display text-lg font-semibold leading-6 text-gray-900">{{ $i + 1 }}</span><span>{{ $step }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= Emergency hotlines =================
         The groups in columns on desktop, stacked on a phone. Every number comes
         from the hotlines table (App\Support\Hotlines). These are public agency
         and office lines only — no personnel account's email or contact_number
         ever reaches this page (LandingOfficerTest). --}}
    <section id="hotlines" class="scroll-mt-16" data-hotlines>
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-16" data-hotline-list>
            <h2 class="flex items-center gap-4 text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-700" aria-hidden="true"><x-icon name="call" /></span>
                Emergency Hotlines
            </h2>
            <p class="mt-2 text-base text-gray-600">Tap a number to call. Use the numbers for the municipality where your campus is.</p>

            {{-- Masonry via CSS columns: the cards differ a lot in height (Baler is
                 the long one), so they flow down each column instead of in rows,
                 which leaves no tall gaps. break-inside: avoid keeps every card
                 whole — a card must never split across two columns. --}}
            <div class="mt-8 columns-1 gap-4 sm:columns-2 lg:columns-3" data-hotline-columns>
                @foreach ($hotlineGroups as $group)
                    <div class="card mb-4 w-full break-inside-avoid" style="break-inside: avoid;" data-hotline-group>
                        <h3 class="card-title">{{ $group['title'] }}</h3>
                        @isset($group['subtitle'])
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-600">{{ $group['subtitle'] }}</p>
                        @endisset
                        @isset($group['note'])
                            <p class="mt-2 text-xs leading-5 text-gray-600" data-hotline-note>{{ $group['note'] }}</p>
                        @endisset
                        <ul class="mt-4 divide-y divide-gray-100 text-sm">
                            @foreach ($group['entries'] as $entry)
                                <li class="py-2" data-hotline-entry>
                                    <span class="block font-medium text-gray-900">{{ $entry['label'] }}</span>
                                    @if (isset($entry['radio']))
                                        {{-- A radio frequency, not a phone number: never a tel: link. --}}
                                        <span class="mt-1 inline-flex items-center gap-2 text-gray-900" data-hotline-radio>
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-gray-700">Radio</span>
                                            {{ $entry['radio'] }}
                                        </span>
                                    @elseif (! empty($entry['numbers']))
                                        <span class="mt-1 flex flex-wrap gap-x-4">
                                            @foreach ($entry['numbers'] as $number)
                                                <a href="{{ $telHref($number) }}" class="inline-flex min-h-[2.75rem] items-center font-semibold text-accent-ink underline-offset-2 hover:underline sm:min-h-0" data-hotline-number>{{ $number }}</a>
                                            @endforeach
                                        </span>
                                    @else
                                        <span class="mt-1 block italic text-gray-600" data-hotline-pending>to be provided</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= The phone app ================= --}}
    @if ($hasApp)
        <section class="border-y border-gray-100 bg-white" data-app-band>
            <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 md:flex-row md:items-center md:justify-between">
                <div class="flex items-start gap-4">
                    <x-icon name="mobile_arrow_down" class="mt-1 h-7 w-7 shrink-0 text-gray-700" />
                    <div>
                        <h2 class="section-title">ASCOT DRRM on your phone</h2>
                        <p class="mt-1 max-w-xl text-sm leading-6 text-gray-600">Press SOS, report a hazard with a photo and location, and get advisories. It installs from this website, no app store needed.</p>
                    </div>
                </div>
                <a href="{{ route('app.welcome') }}" class="btn btn-primary btn-lg shrink-0">Install the app</a>
            </div>
        </section>
    @endif

    {{-- ================= About: the office and what the system covers ================= --}}
    @php
        $modules = [
            ['icon' => 'apartment', 'title' => 'Facility Hazard Auditor', 'text' => 'Record buildings and areas, and inspect them for hazards with a risk rating.'],
            ['icon' => 'crisis_alert', 'title' => 'Incident Reporting', 'text' => 'Log incidents as they happen, track their status, and document the damage.'],
            ['icon' => 'campaign', 'title' => 'Disaster Advisories', 'text' => 'Typhoon, earthquake and flood warnings from official bulletins, shown while they last.'],
            ['icon' => 'fact_check', 'title' => 'Drill Tracker', 'text' => 'Schedule drills on a calendar, document them, and file After Activity Reports.'],
            ['icon' => 'sms', 'title' => 'SMS & Broadcast Alert', 'text' => 'Send emergency text alerts tied to an incident and track their delivery.'],
            ['icon' => 'mobile_arrow_down', 'title' => 'Phone app for students and staff', 'text' => 'Press SOS, report a hazard with a photo and location, and get advisories on your phone.'],
        ];
    @endphp
    <section id="about" class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-16" data-drrm-office>
        <div class="grid gap-8 lg:grid-cols-[1fr_2fr]">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900">The DRRM Office</h2>
                <div class="mt-4 space-y-4 text-base leading-7 text-gray-600">
                    <p>
                        The Disaster Risk Reduction and Management Office keeps ASCOT's students, staff and
                        facilities safe before, during and after emergencies. It identifies hazards on campus,
                        coordinates the response when something happens, and makes sure everyone knows what to do.
                    </p>
                    <p>
                        The office plans and runs regular earthquake, fire and evacuation drills, and maintains
                        response plans for the Zabali, Bazal and Casiguran campuses together with each campus's
                        DRRM coordinators.
                    </p>
                </div>
            </div>
            <div>
                <h2 class="eyebrow">What the System Covers</h2>
                <div class="drrms-stagger mt-4 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-gray-100 bg-gray-100 sm:grid-cols-2">
                    @foreach ($modules as $module)
                        <div class="flex gap-4 bg-white p-6">
                            <x-icon :name="$module['icon']" class="h-6 w-6 shrink-0 text-gray-700" />
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $module['title'] }}</h3>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ $module['text'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <footer class="border-t border-gray-100 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 md:flex-row md:items-start md:justify-between">
            <div class="flex items-center gap-4">
                <img src="{{ asset('images/drrm-logo-256.png') }}" alt="" class="h-12 w-12">
                <div>
                    <p class="text-sm font-semibold text-gray-900">Aurora State College of Technology</p>
                    <p class="text-sm text-gray-600">Disaster Risk Reduction &amp; Management Office</p>
                </div>
            </div>
            <div class="text-sm text-gray-600 md:text-right">
                <p class="leading-6">{{ $campuses->pluck('campus_name')->implode(' · ') }}</p>
                {{-- A plain link only: no embed and no Facebook script, so the page itself
                     makes no request to Facebook (LandingPageTest). --}}
                <p class="mt-1" data-facebook-link>
                    Updates: <a href="https://www.facebook.com/profile.php?id=61579337100805" target="_blank" rel="noopener noreferrer"
                                class="font-medium text-accent-ink underline-offset-2 hover:underline">ASCOT DRRMO on Facebook</a>
                </p>
                <p class="mt-4 text-xs text-gray-600">&copy; {{ now()->year }} ASCOT-DRRMS. All rights reserved.</p>
            </div>
        </div>
    </footer>
@endsection

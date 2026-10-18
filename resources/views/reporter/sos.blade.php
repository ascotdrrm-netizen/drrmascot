@extends('layouts.mobile')

@section('title', 'SOS - ASCOT DRRMS')

@php
    // The DRRMO office line from config/hotlines.php (the ASCOT group).
    $ascot = collect(\App\Support\Hotlines::groups())->firstWhere('title', 'ASCOT');
    $office = collect($ascot['entries'] ?? [])->first(fn ($e) => ! empty($e['numbers']));
    $officeNumber = $office['numbers'][0] ?? null;
    $tel = fn (string $n) => 'tel:' . preg_replace('/[^0-9+]/', '', $n);
@endphp

@section('content')
    {{-- One press starts a 5-second countdown with a big Cancel (thesis panel:
         "1 press button for help"); pocket presses can still be undone. --}}
    <div x-data="sosFlow({ statusUrl: @js(route('app.sos.status')), submitUrl: @js(route('api.reporter.sos.store')), live: @js(auth()->user()?->role === 'Reporter'), smsTo: @js($officeNumber ? preg_replace('/[^0-9+]/', '', $officeNumber) : '') })" class="flex min-h-[70vh] flex-col items-center text-center" data-sos>
        <h1 class="page-title" x-text="title">Need help now?</h1>
        <p class="mt-1 max-w-xs text-sm text-gray-600" x-text="subtitle">Press the button once. You have 5 seconds to cancel.</p>

        {{-- What kind of help (optional; it can be sent without choosing). --}}
        <div class="mt-6 flex flex-wrap justify-center gap-2" x-show="state === 'idle'" role="radiogroup" aria-label="Type of emergency (optional)">
            @foreach (['Medical', 'Fire', 'Trapped', 'Security', 'Other'] as $type)
                <button type="button" role="radio" :aria-checked="type === '{{ $type }}'" x-on:click="type = (type === '{{ $type }}' ? null : '{{ $type }}')"
                        class="rounded-full border px-4 py-2 text-sm font-medium"
                        :class="type === '{{ $type }}' ? 'border-red-600 bg-red-600 text-snow' : 'border-gray-300 bg-white text-gray-800'">{{ $type }}</button>
            @endforeach
        </div>

        {{-- The button. --}}
        <button type="button" x-on:click="press()" :disabled="state !== 'idle'"
                class="relative mt-10 flex h-56 w-56 items-center justify-center rounded-full bg-red-600 text-snow ring-[12px] ring-red-600/20 transition active:scale-95 disabled:opacity-100"
                aria-describedby="sos-help">
            <span x-show="state === 'idle'" class="flex flex-col items-center">
                <x-icon name="sos" class="h-20 w-20" />
                <span class="text-sm font-semibold uppercase tracking-widest">Press for help</span>
            </span>
            <span x-show="state === 'counting'" x-cloak class="font-display text-8xl font-bold" x-text="seconds" aria-live="assertive"></span>
            <span x-show="state === 'sending'" x-cloak class="text-lg font-semibold">Sending…</span>
        </button>
        <p id="sos-help" class="mt-4 text-xs text-gray-600" x-show="state === 'idle'">If available, your location is sent to the DRRM Office and your campus Coordinator.</p>

        <button type="button" x-show="state === 'counting'" x-cloak x-on:click="cancel()"
                class="mt-8 min-h-[3.5rem] w-full max-w-xs rounded-xl border-2 border-gray-300 bg-white text-lg font-semibold">
            Cancel
        </button>

        {{-- No signal: an SMS with your coordinates, and direct calls. --}}
        <div x-show="offline" x-cloak class="mt-8 w-full rounded-xl border border-amber-200 bg-amber-50 p-4 text-left text-amber-800">
            <p class="flex items-center gap-2 font-semibold"><x-icon name="wifi_off" /> No internet: send it by text instead</p>
            @if ($officeNumber)
                <a :href="smsHref" class="btn btn-primary mt-3 w-full"><x-icon name="sms" /> Text the DRRM Office</a>
            @endif
        </div>

        <div class="mt-auto grid w-full grid-cols-2 gap-3 pt-10">
            @if ($officeNumber)
                <a href="{{ $tel($officeNumber) }}" class="btn btn-secondary"><x-icon name="call" /> DRRM Office</a>
            @endif
            <a href="{{ route('app.hotlines') }}" class="btn btn-secondary"><x-icon name="call" /> All hotlines</a>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('sosFlow', function (opts) {
                return {
                    state: 'idle', seconds: 5, type: null, timer: null, offline: false, coords: null,
                    clientKey: window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }),
                    get title() { return { idle: 'Need help now?', counting: 'Sending SOS in…', sending: 'Sending your SOS' }[this.state]; },
                    get subtitle() { return this.state === 'counting' ? 'Press Cancel if this was a mistake.' : 'Press the button once. You have 5 seconds to cancel.'; },
                    get smsHref() {
                        var where = this.coords ? ' Location: https://maps.google.com/?q=' + this.coords.latitude.toFixed(6) + ',' + this.coords.longitude.toFixed(6) : '';
                        return 'sms:' + opts.smsTo + '?body=' + encodeURIComponent('SOS' + (this.type ? ' (' + this.type + ')' : '') + ' from the ASCOT DRRMS app.' + where);
                    },
                    press: function () {
                        var self = this;
                        if (navigator.vibrate) navigator.vibrate(200);
                        // Start finding the location now, so it's ready when the countdown ends.
                        if (navigator.geolocation) {
                            navigator.geolocation.getCurrentPosition(function (p) { self.coords = p.coords; }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
                        }
                        this.state = 'counting'; this.seconds = 5;
                        this.timer = setInterval(function () {
                            self.seconds--;
                            if (self.seconds <= 0) { clearInterval(self.timer); self.send(); }
                        }, 1000);
                    },
                    cancel: function () { clearInterval(this.timer); this.state = 'idle'; },
                    send: async function () {
                        this.state = 'sending';
                        if (! navigator.onLine) { this.offline = true; this.state = 'idle'; return; }
                        if (opts.live) {
                            try {
                                var payload = { client_key: this.clientKey, type: this.type };
                                if (this.coords) { payload.latitude = this.coords.latitude; payload.longitude = this.coords.longitude; payload.accuracy_m = Math.round(this.coords.accuracy); }
                                await window.drrmsAjax.send({ method: 'post', url: opts.submitUrl, data: payload });
                            } catch (error) {
                                this.state = 'idle';
                                this.offline = ! error.response;
                                window.drrmsAjax.toast(error.response?.data?.message || 'SOS did not reach the office. Use the text or call button now.', 'danger');
                                return;
                            }
                        }
                        window.location.href = opts.statusUrl;
                    },
                };
            });
        });
    </script>
@endpush

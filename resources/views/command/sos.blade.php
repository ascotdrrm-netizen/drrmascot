@extends('layouts.app')

@section('title', 'SOS Console - ASCOT DRRMS')
@section('heading', 'SOS Console')
@section('subheading', 'People who pressed SOS in the phone app, the longest wait first. Acknowledge so they know help is coming.')

@php
    $tel = fn (string $n) => 'tel:' . preg_replace('/\D/', '', $n);
    $data = collect($alerts)->map(fn ($a) => array_merge($a, [
        'sent' => $a['sent_at']->getTimestamp(),
        'sent_label' => $a['sent_at']->format('g:i A'),
        'tel' => $tel($a['phone']),
    ]))->sortBy([['acknowledged', 'asc'], ['sent', 'asc']])->values();
@endphp

@section('content')
    <div x-data="sosConsole({ alerts: @js($data), updateUrl: @js(url('/api/command/sos')), pollUrl: @js(route('api.command.sos')) })" data-sos-console>
        {{-- Alarm: browsers allow sound only after a click. --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
            <p class="flex items-center gap-3 font-semibold">
                <x-icon name="sos" class="h-6 w-6" />
                <span x-text="waiting + ' waiting for acknowledgement · ' + alerts.length + ' active'"></span>
            </p>
            <button type="button" class="btn btn-secondary btn-sm" x-on:click="toggleSound()" :aria-pressed="sound">
                <x-icon name="notifications" class="h-4 w-4" /> <span x-text="sound ? 'Alarm sound on' : 'Turn on alarm sound'"></span>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
            {{-- The alerts. --}}
            <div class="space-y-4">
                <template x-for="a in alerts" :key="a.id">
                    <article class="rounded-xl border p-5" :class="a.acknowledged ? 'border-gray-100 bg-white' : 'border-red-300 bg-red-50'">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide" :class="a.acknowledged ? 'text-gray-500' : 'text-red-700'">
                                    <x-icon name="sos" class="h-4 w-4" /> <span x-text="(a.type || 'Type not given') + ' · ' + a.campus + ' Campus'"></span>
                                </p>
                                <h2 class="mt-1 text-lg font-semibold text-gray-900" x-text="a.reporter + ' (' + a.role + ')'"></h2>
                                <p class="flex flex-wrap items-center gap-x-3 text-sm text-gray-600" data-sos-location>
                                    <span x-text="a.place"></span>
                                    <a x-show="a.map_url" :href="a.map_url" target="_blank" rel="noopener" class="link inline-flex items-center gap-1"><x-icon name="map" class="h-4 w-4" /> Open in Google Maps</a>
                                </p>
                            </div>
                            <div class="flex items-start gap-2">
                                <div class="text-right">
                                    <p class="font-display text-4xl font-semibold tabular-nums" :class="a.acknowledged ? 'text-gray-900' : 'text-red-700'" x-text="elapsed(a)"></p>
                                    <p class="text-xs text-gray-500" x-text="'pressed at ' + a.sent_label"></p>
                                </div>
                                {{-- Options: a false alarm is rejected (kept in the records); delete moves it to the Archive. --}}
                                <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" data-sos-options>
                                    <button type="button" class="btn btn-secondary btn-sm px-2" x-on:click="open = ! open" :aria-expanded="open" aria-haspopup="menu" aria-label="More options"><x-icon name="more_vert" class="h-5 w-5" /></button>
                                    <div x-show="open" x-cloak role="menu" class="absolute right-0 z-[1000] mt-2 w-56 rounded-xl border border-gray-200 bg-white p-2 text-left">
                                        <button type="button" role="menuitem" class="nav-item w-full text-left" x-on:click="open = false; reject(a)"><x-icon name="block" class="h-5 w-5" /> Reject (false alarm)</button>
                                        <button type="button" role="menuitem" class="nav-item w-full text-left text-red-700" x-on:click="open = false; remove(a)"><x-icon name="delete" class="h-5 w-5" /> Delete</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="btn btn-danger-solid" x-show="! a.acknowledged" x-on:click="move(a, 'Acknowledged')"><x-icon name="check_circle" class="h-4 w-4" /> Acknowledge</button>
                            <button type="button" class="btn btn-primary" x-show="a.status === 'Acknowledged'" x-on:click="move(a, 'Responding')">Help on the way</button>
                            <button type="button" class="btn btn-primary" x-show="a.status === 'Responding'" x-on:click="move(a, 'Resolved')">Resolve</button>
                            <a :href="a.tel" class="btn btn-secondary"><x-icon name="call" class="h-4 w-4" /> Call them</a>
                            <button type="button" class="btn btn-secondary" x-on:click="focus(a)"><x-icon name="my_location" class="h-4 w-4" /> Show on map</button>
                        </div>
                    </article>
                </template>
            </div>

            {{-- Map of every SOS, and who to escalate to. --}}
            <div class="space-y-6">
                <div class="overflow-hidden rounded-xl border border-gray-100"><div class="h-80" data-sos-map></div></div>
                <div class="card">
                    <h2 class="card-title mb-3">Escalate</h2>
                    <p class="caption mb-3 leading-5">Call outside help for anything campus security can’t handle.</p>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($escalate as $line)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <span class="font-medium text-gray-900">{{ $line['label'] }}</span>
                                <a href="{{ $tel($line['number']) }}" class="link whitespace-nowrap">{{ $line['number'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('sosConsole', function (opts) {
                var tiles = @json(config('map.layers.street.tiles'));
                return {
                    alerts: opts.alerts, now: Math.floor(Date.now() / 1000), sound: false, audio: null, map: null,
                    get waiting() { return this.alerts.filter(function (a) { return ! a.acknowledged; }).length; },
                    init: function () {
                        var self = this;
                        setInterval(function () { self.now = Math.floor(Date.now() / 1000); if (self.sound && self.waiting) self.beep(); }, 1000);
                        setInterval(function () { if (document.visibilityState === 'visible') self.refresh(); }, 10000);
                        this.$nextTick(function () { self.drawMap(); });
                    },
                    refresh: async function () {
                        try {
                            var response = await fetch(opts.pollUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                            if (! response.ok) return;
                            var incoming = (await response.json()).map(function (s) {
                                var when = new Date(s.created_at);
                                var phone = s.reporter?.contact_number || '';
                                return {
                                    id: s.sos_id, type: s.type, status: s.status, acknowledged: s.status !== 'New',
                                    reporter: s.reporter?.full_name || 'Former reporter', role: s.reporter?.reporter_kind || 'Reporter',
                                    campus: s.campus?.campus_name || 'Campus', phone: phone,
                                    tel: 'tel:' + phone.replace(/\D/g, ''), place: s.location_label, map_url: s.map_url,
                                    lat: s.latitude === null ? null : Number(s.latitude),
                                    lng: s.longitude === null ? null : Number(s.longitude), accuracy: s.accuracy_m,
                                    sent: Math.floor(when.getTime() / 1000), sent_label: when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
                                };
                            });
                            var before = this.alerts.map(function (a) { return a.id + ':' + a.status; }).join(',');
                            var after = incoming.map(function (a) { return a.id + ':' + a.status; }).join(',');
                            if (before === after) return;
                            this.alerts = incoming;
                            this.$nextTick(() => { if (this.map) { this.map.remove(); this.map = null; } this.drawMap(); });
                        } catch (_) { /* Keep the last known alerts until the connection returns. */ }
                    },
                    elapsed: function (a) {
                        var s = Math.max(0, this.now - a.sent);
                        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
                    },
                    move: async function (a, status) {
                        try {
                            await window.drrmsAjax.send({ method: 'patch', url: opts.updateUrl + '/' + a.id, data: { status: status } });
                            a.acknowledged = true; a.status = status;
                            if (status === 'Resolved') this.alerts = this.alerts.filter(function (item) { return item.id !== a.id; });
                        } catch (error) {
                            window.drrmsAjax.toast(error.response?.data?.message || 'Could not update the SOS.', 'danger');
                        }
                    },
                    // Takes the SOS off the console, then redraws the map without it.
                    drop: function (a) {
                        this.alerts = this.alerts.filter(function (item) { return item.id !== a.id; });
                        this.$nextTick(() => { if (this.map) { this.map.remove(); this.map = null; } this.drawMap(); });
                    },
                    reject: function (a) {
                        var self = this;
                        window.drrmsConfirm('SOS #' + a.id + ' from ' + a.reporter + ' will be closed as a false alarm. It stays in the records and reports, and the sender is told.', async function () {
                            try {
                                await window.drrmsAjax.send({ method: 'patch', url: opts.updateUrl + '/' + a.id, data: { status: 'Rejected' } });
                                self.drop(a);
                                window.drrmsAjax.toast('SOS #' + a.id + ' rejected as a false alarm.');
                            } catch (error) {
                                window.drrmsAjax.toast(error.response?.data?.message || 'Could not reject the SOS.', 'danger');
                            }
                        }, { title: 'Reject this SOS?', button: 'Reject SOS', tone: 'danger' });
                    },
                    remove: function (a) {
                        var self = this;
                        window.drrmsConfirm('SOS #' + a.id + ' moves to the Archive and leaves this console. You can restore it from the Archive.', async function () {
                            try {
                                var response = await window.drrmsAjax.send({ method: 'delete', url: opts.updateUrl + '/' + a.id });
                                self.drop(a);
                                window.drrmsAjax.toast(response.data?.message || 'SOS deleted.');
                            } catch (error) {
                                window.drrmsAjax.toast(error.response?.data?.message || 'Could not delete the SOS.', 'danger');
                            }
                        }, { title: 'Delete this SOS?', button: 'Delete', tone: 'danger' });
                    },
                    drawMap: function () {
                        var el = this.$root.querySelector('[data-sos-map]');
                        if (! window.L || ! el) return;
                        var map = this.map = L.map(el, { scrollWheelZoom: false });
                        tiles.forEach(function (t) { L.tileLayer(t.url, { attribution: t.attribution, maxNativeZoom: t.max_native_zoom, maxZoom: t.max_zoom }).addTo(map); });
                        var bounds = [];
                        this.alerts.forEach(function (a) {
                            if (a.lat === null || a.lng === null) return;
                            L.circleMarker([a.lat, a.lng], { radius: 10, color: '#dc2626', weight: 3, fillColor: '#ef4444', fillOpacity: 0.5 })
                                .addTo(map).bindPopup(a.reporter + ' · ' + a.place);
                            bounds.push([a.lat, a.lng]);
                        });
                        if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] });
                        else map.setView([15.736, 121.549], 10);
                    },
                    focus: function (a) { if (this.map && a.lat !== null && a.lng !== null) this.map.setView([a.lat, a.lng], 17); },
                    toggleSound: function () {
                        this.sound = ! this.sound;
                        if (this.sound && ! this.audio) this.audio = new (window.AudioContext || window.webkitAudioContext)();
                    },
                    beep: function () {
                        if (! this.audio || this.now % 3 !== 0) return;
                        var o = this.audio.createOscillator(), g = this.audio.createGain();
                        o.frequency.value = 880; g.gain.value = 0.08;
                        o.connect(g); g.connect(this.audio.destination);
                        o.start(); o.stop(this.audio.currentTime + 0.25);
                    },
                };
            });
        });
    </script>
@endpush

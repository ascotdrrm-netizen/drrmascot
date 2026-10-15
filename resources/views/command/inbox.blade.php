@extends('layouts.app')

@section('title', 'Reports Inbox - ASCOT DRRMS')
@section('heading', 'Reports Inbox')
@section('subheading', 'Hazards reported by students and staff from the phone app. Verify each one, send help, and close it when it’s done.')

@php
    $data = collect($reports)->map(fn ($r) => array_merge($r, [
        'when_human' => $r['when']->diffForHumans(),
        'when_full' => $r['when']->format('M j, g:i A'),
        'tel' => 'tel:' . preg_replace('/\D/', '', $r['phone']),
        'sms' => 'sms:' . preg_replace('/\D/', '', $r['phone']),
    ]))->values();
    $tones = [
        'New' => 'border-red-200 bg-red-50 text-red-700',
        'Verified' => 'border-blue-200 bg-blue-50 text-blue-700',
        'Responding' => 'border-amber-200 bg-amber-50 text-amber-700',
        'Resolved' => 'border-green-200 bg-green-50 text-green-700',
        'Rejected' => 'border-gray-200 bg-gray-50 text-gray-600',
    ];
@endphp

@section('content')
    <div x-data="reportsInbox({ reports: @js($data), tones: @js($tones), updateUrl: @js(url('/api/command/reports')) })" data-reports-inbox>
        {{-- Status filter. --}}
        <div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Filter by status">
            <template x-for="f in filters" :key="f">
                <button type="button" role="tab" :aria-selected="filter === f" x-on:click="filter = f"
                        class="inline-flex min-h-[2.25rem] items-center gap-2 rounded-full border px-3 text-sm font-medium"
                        :class="filter === f ? 'border-accent-line bg-accent-soft text-gray-900' : 'border-gray-200 bg-white text-gray-600 hover:text-gray-900'">
                    <span x-text="f"></span>
                    <span class="rounded-full bg-gray-100 px-1.5 text-xs tabular-nums" x-text="count(f)"></span>
                </button>
            </template>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
            {{-- The list. --}}
            <div class="card-flush self-start">
                <p class="p-5 text-sm text-gray-600" x-show="reports.length === 0">No Reporter submissions yet.</p>
                <ul class="max-h-[70vh] divide-y divide-gray-100 overflow-y-auto">
                    <template x-for="r in visible" :key="r.id">
                        <li>
                            <button type="button" x-on:click="select(r.id)" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-gray-50"
                                    :class="selected && selected.id === r.id ? 'bg-accent-soft' : ''">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="r.status === 'New' ? 'bg-red-500' : 'bg-transparent'" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-semibold text-gray-900" x-text="r.category"></span>
                                        <span class="shrink-0 text-xs text-gray-500" x-text="r.when_human"></span>
                                    </span>
                                    <span class="block truncate text-xs text-gray-600" x-text="r.campus + ' · ' + r.place"></span>
                                    <span class="mt-1 flex items-center gap-2">
                                        <span class="chip" :class="tones[r.status]" x-text="r.status"></span>
                                        <span x-show="r.urgent" class="chip border-red-200 bg-red-50 text-red-700">Urgent</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </template>
                    <li x-show="visible.length === 0" class="px-4 py-10 text-center text-sm text-gray-500">No reports here.</li>
                </ul>
            </div>

            {{-- The selected report. --}}
            <div class="card space-y-5" x-show="selected" x-cloak>
                <template x-if="selected">
                    <div class="space-y-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs text-gray-500" x-text="'Report #' + selected.id + ' · ' + selected.when_full"></p>
                                <h2 class="text-xl font-semibold text-gray-900" x-text="selected.category"></h2>
                                <p class="text-sm text-gray-600" x-text="selected.campus + ' Campus · ' + selected.place"></p>
                            </div>
                            <span class="chip" :class="tones[selected.status]" x-text="selected.status"></span>
                        </div>

                        <p class="text-sm leading-6 text-gray-800" x-text="selected.description"></p>

                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100 sm:grid-cols-3">
                            <div class="bg-white p-3"><dt class="text-xs text-gray-500">People hurt</dt><dd class="font-display text-2xl font-semibold" x-text="selected.injured ?? 'Not known'"></dd></div>
                            <div class="bg-white p-3"><dt class="text-xs text-gray-500">Location accuracy</dt><dd class="font-display text-2xl font-semibold" x-text="'± ' + selected.accuracy + ' m'"></dd></div>
                            <div class="col-span-2 bg-white p-3 sm:col-span-1">
                                <dt class="text-xs text-gray-500">Suggested tags</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    <template x-for="t in selected.tags" :key="t"><span class="chip border-gray-200 text-gray-700" x-text="t"></span></template>
                                    <span x-show="selected.tags.length === 0" class="text-sm text-gray-500">None</span>
                                </dd>
                            </div>
                        </dl>

                        <div x-show="selected.caption" class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm text-gray-700">
                            <x-icon name="photo_camera" class="text-gray-500" />
                            <span>Photo: <span x-text="selected.caption"></span></span>
                        </div>
                        <div class="flex gap-3" x-show="selected.photo_url || selected.proof_url">
                            <a class="link text-sm" x-show="selected.photo_url" :href="selected.photo_url" target="_blank" rel="noopener">View photo</a>
                            <a class="link text-sm" x-show="selected.proof_url" :href="selected.proof_url" target="_blank" rel="noopener">View proof</a>
                        </div>

                        <div class="overflow-hidden rounded-lg border border-gray-100"><div class="h-56" data-inbox-map></div></div>

                        {{-- The reporter. --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-100 p-3">
                            <p class="text-sm"><span class="font-semibold text-gray-900" x-text="selected.reporter"></span> <span class="text-gray-500" x-text="'· ' + selected.role + ' · ' + selected.phone"></span></p>
                            <div class="flex gap-2">
                                <a :href="selected.tel" class="btn btn-secondary btn-sm"><x-icon name="call" class="h-4 w-4" /> Call</a>
                                <a :href="selected.sms" class="btn btn-secondary btn-sm"><x-icon name="sms" class="h-4 w-4" /> Text</a>
                            </div>
                        </div>

                        {{-- What to do with it. --}}
                        <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-5">
                            <button type="button" class="btn btn-primary" x-show="selected.status === 'New'" x-on:click="move('Verified')"><x-icon name="verified_user" class="h-4 w-4" /> Verify</button>
                            <button type="button" class="btn btn-primary" x-show="selected.status === 'Verified'" x-on:click="move('Responding')"><x-icon name="directions_car" class="h-4 w-4" /> Send help</button>
                            <button type="button" class="btn btn-primary" x-show="selected.status === 'Responding'" x-on:click="move('Resolved')"><x-icon name="check_circle" class="h-4 w-4" /> Resolve</button>
                            <a href="{{ route($routePrefix . '.incidents.create') }}" class="btn btn-secondary" x-show="selected.status !== 'Rejected'"><x-icon name="crisis_alert" class="h-4 w-4" /> Make it an incident</a>
                            <button type="button" class="btn btn-danger" x-show="['New', 'Verified'].includes(selected.status)" x-on:click="move('Rejected')">Reject</button>
                        </div>
                        <p class="text-xs text-gray-500" x-show="note" x-text="note" role="status"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script>
        document.addEventListener('alpine:init', function () {
            Alpine.data('reportsInbox', function (opts) {
                var tiles = @json(config('map.layers.street.tiles'));
                return {
                    reports: opts.reports, tones: opts.tones, filter: 'All', filters: ['All', 'New', 'Verified', 'Responding', 'Resolved', 'Rejected'],
                    selected: null, note: '', map: null, marker: null,
                    init: function () { if (this.reports.length) this.select(this.reports[0].id); },
                    get visible() { var f = this.filter; return this.reports.filter(function (r) { return f === 'All' || r.status === f; }); },
                    count: function (f) { return f === 'All' ? this.reports.length : this.reports.filter(function (r) { return r.status === f; }).length; },
                    select: function (id) {
                        var self = this;
                        this.selected = this.reports.find(function (r) { return r.id === id; });
                        this.note = '';
                        this.$nextTick(function () { self.drawMap(); });
                    },
                    drawMap: function () {
                        var el = this.$root.querySelector('[data-inbox-map]');
                        if (! window.L || ! el) return;
                        if (! this.selected || this.selected.lat === null || this.selected.lng === null) return;
                        var at = [this.selected.lat, this.selected.lng];
                        if (! this.map || this.map.getContainer() !== el) {
                            if (this.map) this.map.remove();
                            this.map = L.map(el, { scrollWheelZoom: false });
                            var map = this.map;
                            tiles.forEach(function (t) { L.tileLayer(t.url, { attribution: t.attribution, maxNativeZoom: t.max_native_zoom, maxZoom: t.max_zoom }).addTo(map); });
                            this.marker = L.circleMarker(at, { radius: 9, color: '#f97316', weight: 3, fillColor: '#f97316', fillOpacity: 0.4 }).addTo(map);
                        }
                        this.marker.setLatLng(at);
                        this.map.setView(at, 17);
                        this.map.invalidateSize();
                    },
                    move: async function (status) {
                        try {
                            await window.drrmsAjax.send({ method: 'patch', url: opts.updateUrl + '/' + this.selected.id, data: { status: status } });
                            this.selected.status = status;
                            this.note = 'Status set to ' + status + '. The reporter can see it on their phone.';
                        } catch (error) {
                            window.drrmsAjax.toast(error.response?.data?.message || 'Could not update the report.', 'danger');
                        }
                    },
                };
            });
        });
    </script>
@endpush

@extends('layouts.app')

@section('title', $building->building_name . ' - ASCOT DRRMS')
@section('heading', $building->building_name)

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.index") }}'>Back to Buildings/Areas</x-back-link>
@endsection

@section('actions')
    <a href="{{ route("{$routePrefix}.buildings.report", $building) }}" data-confirm="Download a PDF report of this building/area and its inspections?" class="btn btn-secondary">Download PDF</a>
    <a href="{{ route("{$routePrefix}.buildings.edit", $building) }}" class="btn btn-primary">Edit Building/Area</a>
@endsection

@section('content')
    @php
        $emptyMessage = $routePrefix === 'admin' ? 'No inspections recorded yet.' : 'You haven\'t recorded any inspections for this building yet.';
        $emptyHint = $routePrefix === 'admin'
            ? 'Inspections that coordinators record for this building/area appear here.'
            : 'Walk the building, note any hazards you find, then use + Record an inspection above. Inspections by other coordinators are not shown to you.';
    @endphp
    <x-card class="page-section max-w-3xl">
        @if ($building->photo_path)
            <img src="{{ $building->photo_url }}" alt="{{ $building->photo_caption ?: $building->building_name }}" class="mb-4 w-full max-w-md rounded-lg">
            @if ($building->photo_caption)<p class="-mt-2 mb-4 text-sm text-gray-600" data-photo-caption>{{ $building->photo_caption }}</p>@endif
        @endif

        <dl class="detail-list">
            @if ($building->description)
                <div><dt class="text-gray-600">Description</dt><dd class="mt-1 whitespace-pre-line text-gray-900">{{ $building->description }}</dd></div>
            @endif
            <div class="flex justify-between">
                <dt class="text-gray-600">Hazard type <x-info-tip term="Hazard type" :text="config('glossary.building_hazard_type')" /></dt>
                <dd class="font-medium text-gray-900">{{ $building->hazard_type ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-gray-600">Risk level <x-info-tip term="Risk level" :text="config('glossary.risk_level')" /></dt>
                <dd>
                    <x-badge :value="$building->risk_level" :map="[
                        'Low' => 'bg-green-100 text-green-700',
                        'Medium' => 'bg-amber-100 text-amber-700',
                        'High' => 'bg-red-100 text-red-700',
                    ]" />
                </dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-600">Campus</dt>
                <dd class="font-medium text-gray-900">{{ $building->campus->campus_name ?? '—' }}</dd>
            </div>
        </dl>

        @if ($building->hasLocation())
            <x-location-map :lat="$building->latitude" :lng="$building->longitude" class="mt-4" />
            <a href="{{ $building->map_url }}" target="_blank" rel="noopener" class="link mt-4 inline-flex min-h-[2.75rem] sm:min-h-0 items-center gap-1 text-sm">
                <x-icon name="location_on" fill class="h-4 w-4" />
                View on Map
            </a>
        @endif
    </x-card>

    <div class="section-header">
        <div>
            <h2 class="section-title">Hazard Inspections</h2>
            <p class="guide-note">{{ $routePrefix === 'admin' ? 'Each inspection records the hazards found on a given date.' : 'Each inspection records the hazards found on a given date. You see only the inspections you recorded.' }}</p>
        </div>
        <div class="toolbar">
            <x-view-toggle group="inspections" />
            <a href="{{ route("{$routePrefix}.buildings.inspections.create", $building) }}" class="btn btn-secondary">+ Record an inspection</a>
        </div>
    </div>

    <div data-view-panel="inspections">
        <div data-view-content="grid" class="drrms-stagger grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse ($building->hazardInspections as $inspection)
                <x-card class="flex flex-col transition-shadow hover:shadow-md">
                    @if ($inspection->photo_path)
                        <a href="{{ $inspection->photo_url }}" target="_blank" rel="noopener">
                            <img src="{{ $inspection->photo_url }}" alt="Inspection photo" class="mb-4 aspect-video w-full rounded-lg object-cover">
                        </a>
                        @if ($inspection->photo_caption)<p class="mb-4 text-sm text-gray-600" data-photo-caption>{{ $inspection->photo_caption }}</p>@endif
                    @else
                        <div class="mb-4 flex aspect-video w-full items-center justify-center rounded-lg bg-gray-100 text-sm text-gray-600">
                            No photo
                        </div>
                    @endif

                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm font-semibold text-gray-900">{{ $inspection->inspection_date->format('M d, Y') }}</span>
                        <x-badge :value="$inspection->risk_level" :map="[
                            'Low' => 'bg-green-100 text-green-700',
                            'Medium' => 'bg-amber-100 text-amber-700',
                            'High' => 'bg-red-100 text-red-700',
                        ]" />
                    </div>

                    <p class="caption leading-6">School: {{ $inspection->school->school_name ?? '—' }}</p>
                    <p class="mb-4 text-sm text-gray-600">{{ \Illuminate\Support\Str::limit($inspection->findings ?? 'No findings recorded.', 150) }}</p>

                    @if ($inspection->hasLocation())
                        <a href="{{ $inspection->map_url }}" target="_blank" rel="noopener" class="link mb-4 inline-flex min-h-[2.75rem] sm:min-h-0 self-start items-center gap-1 text-xs">
                            <x-icon name="location_on" fill class="h-4 w-4" />
                            View on Map
                        </a>
                    @endif

                    <div data-tap-row class="mt-auto flex flex-wrap items-center gap-x-4 border-t border-gray-100 pt-4">
                        <a href="{{ route("{$routePrefix}.buildings.inspections.report", [$building, $inspection]) }}" data-confirm="Download a PDF report of this inspection?" class="link-muted text-sm">Download PDF</a>
                        <a href="{{ route("{$routePrefix}.buildings.inspections.edit", [$building, $inspection]) }}" class="link text-sm">Edit</a>
                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.buildings.inspections.destroy", [$building, $inspection]) }}" data-confirm-form="This permanently deletes the inspection of {{ $inspection->inspection_date->format('M d, Y') }}, including its photo. It cannot be undone." data-confirm-title="Delete inspection?" data-confirm-button="Delete permanently" data-confirm-tone="danger" data-confirm-focus="cancel">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="link-danger text-sm">Delete</button>
                        </form>
                    </div>
                </x-card>
            @empty
                <x-empty-state class="col-span-full" icon="check" :message="$emptyMessage" :hint="$emptyHint" />
            @endforelse
        </div>

        <div data-view-content="table" class="hidden">
            <x-card flush>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="table-th">Photo</th>
                                <th class="table-th">Date</th>
                                <th class="table-th">Risk level <x-info-tip term="Risk level" :text="config('glossary.risk_level')" /></th>
                                <th class="table-th">School</th>
                                <th class="table-th">Findings</th>
                                <th class="table-th actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($building->hazardInspections as $inspection)
                                <tr class="hover:bg-gray-50">
                                    <td class="table-td">
                                        @if ($inspection->photo_path)
                                            <a href="{{ $inspection->photo_url }}" target="_blank" rel="noopener">
                                                <img src="{{ $inspection->photo_url }}" alt="Inspection photo" class="h-10 w-10 rounded-lg object-cover">
                                            </a>
                        @if ($inspection->photo_caption)<p class="mb-4 text-sm text-gray-600" data-photo-caption>{{ $inspection->photo_caption }}</p>@endif
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-600">None</div>
                                        @endif
                                    </td>
                                    <td class="table-td font-medium text-gray-900">{{ $inspection->inspection_date->format('M d, Y') }}</td>
                                    <td class="table-td">
                                        <x-badge :value="$inspection->risk_level" :map="[
                                            'Low' => 'bg-green-100 text-green-700',
                                            'Medium' => 'bg-amber-100 text-amber-700',
                                            'High' => 'bg-red-100 text-red-700',
                                        ]" />
                                    </td>
                                    <td class="table-td text-gray-600">{{ $inspection->school->school_name ?? '—' }}</td>
                                    <td class="table-td text-gray-600">{{ \Illuminate\Support\Str::limit($inspection->findings ?? 'No findings recorded.', 80) }}</td>
                                    <td class="table-td">
                                        <div class="table-actions">
                                            <a href="{{ route("{$routePrefix}.buildings.inspections.report", [$building, $inspection]) }}" data-confirm="Download a PDF report of this inspection?" class="link-muted">Download PDF</a>
                                            <a href="{{ route("{$routePrefix}.buildings.inspections.edit", [$building, $inspection]) }}" class="link">Edit</a>
                                            <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.buildings.inspections.destroy", [$building, $inspection]) }}" data-confirm-form="This permanently deletes the inspection of {{ $inspection->inspection_date->format('M d, Y') }}, including its photo. It cannot be undone." data-confirm-title="Delete inspection?" data-confirm-button="Delete permanently" data-confirm-tone="danger" data-confirm-focus="cancel">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="link-danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="6" icon="check" :message="$emptyMessage" :hint="$emptyHint" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
@endsection

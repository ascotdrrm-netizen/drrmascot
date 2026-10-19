@extends('layouts.app')

@section('title', 'Buildings/Areas - ASCOT DRRMS')
@section('heading', 'Buildings/Areas')
@section('subheading', $routePrefix === 'admin'
    ? 'Every building or open area on each campus that can be inspected for hazards.'
    : 'Every building or open area on your campus that can be inspected for hazards. This list is shared by all coordinators of the campus.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('actions')
    @if ($trashedCount > 0)
        <a href="{{ route("{$routePrefix}.buildings.trash") }}" class="btn btn-secondary">View Trash ({{ $trashedCount }})</a>
    @endif
    <x-generate-report-modal module="hazard" :module-label="'Facility Hazard Auditor'" :campuses="$campuses" :selected-campus-id="$selectedCampusId" />
    <a href="{{ route("{$routePrefix}.buildings.create") }}" class="btn btn-primary">+ Add Building/Area</a>
@endsection

@section('content')
    @php
        $emptyHint = 'List each building or open area here — a classroom block, the gym, a covered court — using + Add Building/Area at the top. You then record hazard inspections on it.';
    @endphp
    {{-- Admin: Campus. Coordinator: none (the list is their campus). No period: a building list is an inventory. --}}
    <x-filter-bar :action="route($routePrefix . '.buildings.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="$campuses ? [['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId]] : []">
        <x-slot:aside><x-view-toggle group="buildings" /></x-slot:aside>
    </x-filter-bar>

    <div data-view-panel="buildings">
        <div data-view-content="grid" class="drrms-stagger grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse ($buildings as $building)
                <x-card class="flex flex-col transition-shadow hover:shadow-md">
                    <a href="{{ route("{$routePrefix}.buildings.show", $building) }}">
                        @if ($building->photo_path)
                            <img src="{{ $building->photo_url }}" alt="{{ $building->building_name }}" class="mb-4 aspect-video w-full rounded-lg object-cover">
                        @else
                            <div class="mb-4 flex aspect-video w-full items-center justify-center rounded-lg bg-gray-100 text-4xl font-semibold text-gray-600">
                                {{ \Illuminate\Support\Str::substr($building->building_name, 0, 1) }}
                            </div>
                        @endif
                    </a>

                    <h3 class="card-title">
                        <a href="{{ route("{$routePrefix}.buildings.show", $building) }}" class="inline-flex min-h-[2.75rem] sm:min-h-0 items-center hover:text-accent-ink">{{ $building->building_name }}</a>
                    </h3>

                    <div class="mb-4 flex flex-wrap items-center gap-x-4 leading-8">
                        <span class="text-sm text-gray-600">{{ $building->hazard_type ?? '—' }}</span>
                        <x-risk-badge :level="$building->risk_level" />
                        @if ($routePrefix === 'admin')
                            <span class="text-sm text-gray-600">{{ $building->campus->campus_name ?? '—' }}</span>
                        @endif
                    </div>

                    @if ($building->hasLocation())
                        <a href="{{ $building->map_url }}" target="_blank" rel="noopener" class="link mb-4 inline-flex min-h-[2.75rem] sm:min-h-0 items-center gap-1 self-start text-xs">
                            <x-icon name="location_on" fill class="h-4 w-4" />
                            View on Map
                        </a>
                    @endif

                    <div data-tap-row class="mt-auto flex flex-wrap items-center gap-x-4 border-t border-gray-100 pt-4">
                        <a href="{{ route("{$routePrefix}.buildings.report", $building) }}" data-confirm="Download a PDF report of this building/area and its inspections?" class="link-muted text-sm">Download PDF</a>
                        <a href="{{ route("{$routePrefix}.buildings.edit", $building) }}" class="link text-sm">Edit</a>
                        {{-- Shared by the campus: only whoever added it, or the Admin, may trash it. --}}
                        @if (\App\Support\RecordAccess::canTrashBuilding($building, Auth::user()))
                            <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.buildings.destroy", $building) }}" data-confirm-form="This moves {{ $building->building_name }} and its inspections to Trash. You can restore it from there, photo included." data-confirm-title="Move to Trash?" data-confirm-button="Move to Trash" data-confirm-tone="danger" data-confirm-focus="cancel">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-danger text-sm">Delete</button>
                            </form>
                        @endif
                    </div>
                </x-card>
            @empty
                <x-empty-state class="col-span-full" icon="buildings" message="No buildings/areas recorded yet." :hint="$emptyHint" />
            @endforelse
        </div>

        <div data-view-content="table" class="hidden">
            <x-card flush>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="table-th">Photo</th>
                                <th class="table-th">Name</th>
                                <th class="table-th">Hazard type <x-info-tip term="Hazard type" :text="config('glossary.building_hazard_type')" /></th>
                                <th class="table-th">Risk level <x-info-tip term="Risk level" :text="config('glossary.risk_level')" /></th>
                                @if ($routePrefix === 'admin')
                                    <th class="table-th">Campus</th>
                                @endif
                                <th class="table-th actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($buildings as $building)
                                <tr class="hover:bg-gray-50">
                                    <td class="table-td">
                                        @if ($building->photo_path)
                                            <img src="{{ $building->photo_url }}" alt="{{ $building->building_name }}" class="h-10 w-10 rounded-lg object-cover">
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-sm font-semibold text-gray-600">
                                                {{ \Illuminate\Support\Str::substr($building->building_name, 0, 1) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="table-td">
                                        <a href="{{ route("{$routePrefix}.buildings.show", $building) }}" class="font-medium text-gray-900 hover:text-accent-ink">{{ $building->building_name }}</a>
                                    </td>
                                    <td class="table-td text-gray-600">{{ $building->hazard_type ?? '—' }}</td>
                                    <td class="table-td">
                                        <x-risk-badge :level="$building->risk_level" />
                                    </td>
                                    @if ($routePrefix === 'admin')
                                        <td class="table-td text-gray-600">{{ $building->campus->campus_name ?? '—' }}</td>
                                    @endif
                                    <td class="table-td">
                                        <div class="table-actions">
                                            <a href="{{ route("{$routePrefix}.buildings.report", $building) }}" data-confirm="Download a PDF report of this building/area and its inspections?" class="link-muted">Download PDF</a>
                                            <a href="{{ route("{$routePrefix}.buildings.edit", $building) }}" class="link">Edit</a>
                                            @if (\App\Support\RecordAccess::canTrashBuilding($building, Auth::user()))
                                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.buildings.destroy", $building) }}" data-confirm-form="This moves {{ $building->building_name }} and its inspections to Trash. You can restore it from there, photo included." data-confirm-title="Move to Trash?" data-confirm-button="Move to Trash" data-confirm-tone="danger" data-confirm-focus="cancel">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="link-danger">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state :colspan="$routePrefix === 'admin' ? 6 : 5" icon="buildings" message="No buildings/areas recorded yet." :hint="$emptyHint" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
@endsection

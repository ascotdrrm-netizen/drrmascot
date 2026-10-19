@extends('layouts.app')

@section('title', 'Buildings/Areas Trash - ASCOT DRRMS')
@section('heading', 'Buildings/Areas — Trash')
@section('subheading', 'Deleted buildings/areas wait here until someone restores them. Restoring brings one back with its inspections and its photo.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.index") }}'>Back to Buildings/Areas</x-back-link>
@endsection

@section('content')
    @php $retentionRunning = \App\Support\TrashPurge::isRunning(); @endphp
    <x-trash-retention-note />

    {{-- Admin: Campus. Coordinator: none (their Trash is their own campus). --}}
    <x-filter-bar :action="route($routePrefix . '.buildings.trash')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="$campuses ? [['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId]] : []" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Name</th>
                        <th class="table-th">Hazard type</th>
                        <th class="table-th">Risk level</th>
                        @if ($routePrefix === 'admin')
                            <th class="table-th">Campus</th>
                        @endif
                        <th class="table-th">Deleted at</th>
                        @if ($retentionRunning)
                            <th class="table-th">Removal</th>
                        @endif
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($buildings as $building)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td font-medium text-gray-900">{{ $building->building_name }}</td>
                            <td class="table-td text-gray-600">{{ $building->hazard_type ?? '—' }}</td>
                            <td class="table-td">
                                <x-risk-badge :level="$building->risk_level" />
                            </td>
                            @if ($routePrefix === 'admin')
                                <td class="table-td text-gray-600">{{ $building->campus->campus_name ?? '—' }}</td>
                            @endif
                            <td class="table-td text-gray-600">{{ $building->deleted_at->format('M d, Y g:i A') }}</td>
                            @if ($retentionRunning)
                                <x-trash-removal-cell :record="$building" />
                            @endif
                            <td class="table-td text-right">
                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.buildings.restore", $building->building_id) }}">
                                    @csrf
                                    <button type="submit" class="link">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="($routePrefix === 'admin' ? 6 : 5) + ($retentionRunning ? 1 : 0)" icon="trash" message="Trash is empty." hint="When a building/area is deleted it comes here, and can be restored from here." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

@extends('layouts.app')

@section('title', 'Incidents Trash - ASCOT DRRMS')
@section('heading', 'Incidents — Trash')
@section('subheading', 'Deleted incidents wait here until someone restores them. Restoring brings one back with its damage reports and alerts and its photo.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.incidents.index") }}'>Back to incidents</x-back-link>
@endsection

@section('content')
    @php $retentionRunning = \App\Support\TrashPurge::isRunning(); @endphp
    <x-trash-retention-note />

    {{-- Admin: Campus + School. Coordinator: School only, within their own campus. --}}
    <x-filter-bar :action="route($routePrefix . '.incidents.trash')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="array_values(array_filter([
                      $campuses ? ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId] : null,
                      ['kind' => 'school', 'schools' => $schools, 'campusSource' => $campuses ? 'campus_id' : null,
                       'campusId' => $campuses ? $selectedCampusId : $userCampusId, 'selected' => $selectedSchoolId],
                  ]))" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Date occurred</th>
                        <th class="table-th">Incident type</th>
                        <th class="table-th">Status</th>
                        @if ($routePrefix === 'admin')
                            <th class="table-th">Campus</th>
                        @endif
                        <th class="table-th">School</th>
                        <th class="table-th">Deleted at</th>
                        @if ($retentionRunning)
                            <th class="table-th">Removal</th>
                        @endif
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($incidents as $incident)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $incident->date_occurred->format('M d, Y') }}</td>
                            <td class="table-td font-medium text-gray-900">{{ $incident->hazard_type }}</td>
                            <td class="table-td">
                                <x-badge :value="$incident->status" :map="[
                                    'Active' => 'bg-red-100 text-red-700',
                                    'Under Response' => 'bg-amber-100 text-amber-700',
                                    'Resolved' => 'bg-green-100 text-green-700',
                                ]" />
                            </td>
                            @if ($routePrefix === 'admin')
                                <td class="table-td text-gray-600">{{ $incident->campus->campus_name ?? '—' }}</td>
                            @endif
                            <td class="table-td text-gray-600">{{ $incident->school->school_name ?? '—' }}</td>
                            <td class="table-td text-gray-600">{{ $incident->deleted_at->format('M d, Y g:i A') }}</td>
                            @if ($retentionRunning)
                                <x-trash-removal-cell :record="$incident" />
                            @endif
                            <td class="table-td text-right">
                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.incidents.restore", $incident->incident_id) }}">
                                    @csrf
                                    <button type="submit" class="link">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="($routePrefix === 'admin' ? 7 : 6) + ($retentionRunning ? 1 : 0)" icon="trash" :message="$routePrefix === 'admin' ? 'Trash is empty.' : 'None of your deleted incidents are in Trash.'" hint="When an incident is deleted it comes here, and can be restored from here." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

@extends('layouts.app')

@section('title', 'Drills Trash - ASCOT DRRMS')
@section('heading', 'Drills — Trash')
@section('subheading', $routePrefix === 'admin'
    ? 'Deleted drills wait here until someone restores them. Restoring brings one back with its documentation, After Activity Report and their files.'
    : 'Drills you scheduled and then deleted wait here until you restore them, with their documentation, After Activity Report and files.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.index") }}'>Back to drills</x-back-link>
@endsection

@section('content')
    @php $retentionRunning = \App\Support\TrashPurge::isRunning(); @endphp
    <x-trash-retention-note />

    {{-- Admin: Campus. Coordinator: none (their Trash is the drills they scheduled). Drills have no school. --}}
    <x-filter-bar :action="route($routePrefix . '.drills.trash')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="$campuses ? [['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId]] : []" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Scheduled date</th>
                        <th class="table-th">Drill type</th>
                        <th class="table-th">Venue</th>
                        @if ($campuses)
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
                    @forelse ($drills as $drill)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $drill->scheduled_date->format('M d, Y') }}</td>
                            <td class="table-td font-medium text-gray-900">{{ $drill->drill_type }}</td>
                            <td class="table-td text-gray-600">{{ $drill->venue ?? '—' }}</td>
                            @if ($campuses)
                                <td class="table-td text-gray-600">{{ $drill->campus->campus_name ?? '—' }}</td>
                            @endif
                            <td class="table-td text-gray-600">{{ $drill->deleted_at->format('M d, Y g:i A') }}</td>
                            @if ($retentionRunning)
                                <x-trash-removal-cell :record="$drill" />
                            @endif
                            <td class="table-td text-right">
                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.drills.restore", $drill->drill_id) }}">
                                    @csrf
                                    <button type="submit" class="link">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="($campuses ? 6 : 5) + ($retentionRunning ? 1 : 0)" icon="trash" message="Trash is empty."
                                       :hint="$campuses ? 'When a drill is deleted it comes here, and can be restored from here.' : 'When you delete a drill you scheduled, it comes here, and you can restore it from here.'" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

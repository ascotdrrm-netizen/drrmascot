@extends('layouts.app')

@section('title', 'Disaster Advisories - ASCOT DRRMS')
@section('heading', 'Disaster Advisories')
@section('subheading', 'Warnings received before or during an event. What actually happened, and the damage it caused, is recorded under Incidents.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('actions')
    @if ($trashedCount > 0)
        <a href="{{ route("{$routePrefix}.advisories.trash") }}" class="btn btn-secondary">View Trash ({{ $trashedCount }})</a>
    @endif
    <a href="{{ route("{$routePrefix}.advisories.create") }}" class="btn btn-primary">+ Issue advisory</a>
@endsection

@php $filtered = $filtersApplied; @endphp

@section('content')
    {{-- Admin: Period, Campus, Status, Hazard. Coordinator: Period, Status, Hazard — no Campus.
         Period filters issued_at; "All dates" is the default. Advisories carry no school. --}}
    <x-filter-bar :action="route($routePrefix . '.advisories.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="array_values(array_filter([
                      ['kind' => 'period', 'allDates' => true, 'values' => $periodValues],
                      $campuses ? ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId] : null,
                      ['kind' => 'select', 'name' => 'status', 'label' => 'Status',
                       'options' => ['' => 'All'] + \App\Models\Advisory\DisasterAdvisory::STATUS_LABELS, 'selected' => $selectedStatus],
                      ['kind' => 'select', 'name' => 'hazard_type', 'label' => 'Hazard',
                       'options' => ['' => 'All'] + array_combine($hazardTypes, $hazardTypes), 'selected' => $selectedHazard],
                  ]))" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Hazard</th>
                        <th class="table-th">Headline</th>
                        @if ($campuses)
                            <th class="table-th">Campus</th>
                        @endif
                        <th class="table-th">Expected</th>
                        <th class="table-th">Status</th>
                        <th class="table-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($advisories as $advisory)
                        <tr class="hover:bg-gray-50" data-advisory-row="{{ $advisory->advisory_id }}">
                            <td class="table-td whitespace-nowrap text-gray-600">{{ $advisory->issued_at->format('M d, Y g:i A') }}</td>
                            <td class="table-td font-medium text-gray-900">{{ $advisory->hazard_label }}</td>
                            <td class="table-td">
                                <a href="{{ route("{$routePrefix}.advisories.show", $advisory) }}" class="link font-medium">{{ $advisory->headline }}</a>
                                @if ($advisory->headline_measurement)
                                    <span class="block text-xs text-gray-600">{{ $advisory->headline_measurement }}</span>
                                @endif
                            </td>
                            @if ($campuses)
                                <td class="table-td text-gray-600">{{ $advisory->campus_label }}</td>
                            @endif
                            <td class="table-td whitespace-nowrap text-gray-600">{{ $advisory->expected_at?->format('M d, Y g:i A') ?? '—' }}</td>
                            <td class="table-td">@include('advisory._status-badge')</td>
                            <td class="table-td text-right">
                                <a href="{{ route("{$routePrefix}.advisories.show", $advisory) }}" class="link">View</a>
                            </td>
                        </tr>
                    @empty
                        @if ($filtered)
                            <x-empty-state :colspan="$campuses ? 7 : 6" icon="disasters" message="No advisories match these filters."
                                           hint="Set the filters above back to All to see every advisory." />
                        @else
                            <x-empty-state :colspan="$campuses ? 7 : 6" icon="disasters" message="No disaster advisories yet."
                                           hint="When PAGASA, PHIVOLCS or a DRRMO issues a bulletin about a typhoon, earthquake, flood, landslide or storm surge that may reach {{ $campuses ? 'a campus' : 'your campus' }}, use + Issue advisory to post it. While it is in effect it shows as a warning on every dashboard for that campus. If the event hits, open the advisory and use Record what happened to start the incident." />
                        @endif
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

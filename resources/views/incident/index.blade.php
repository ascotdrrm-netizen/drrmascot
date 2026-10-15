@extends('layouts.app')

@section('title', 'Incidents - ASCOT DRRMS')
@section('heading', 'Incidents')
@section('subheading', $routePrefix === 'admin'
    ? 'Disasters and campus emergencies reported on every campus — what happened, when, and whether it is still being handled.'
    : 'The incidents you have reported. Report one as soon as it happens; other coordinators\' reports are not shown here.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('actions')
    @if ($trashedCount > 0)
        <a href="{{ route("{$routePrefix}.incidents.trash") }}" class="btn btn-secondary">View Trash ({{ $trashedCount }})</a>
    @endif
    <x-generate-report-modal :modules="['incidents' => 'Incident Reporting', 'alerts' => 'SMS & Broadcast Alert']" :campuses="$campuses" :selected-campus-id="$selectedCampusId" :selected-school-id="$selectedSchoolId" />
    <a href="{{ route("{$routePrefix}.incidents.create") }}" class="btn btn-primary">+ Report incident</a>
@endsection

@section('content')
    {{-- Admin: Period, Campus, School, Incident type, Status. Coordinator: Period, School,
         Incident type, Status — no Campus. Period filters date_occurred; "All dates" is the default. --}}
    <x-filter-bar :action="route($routePrefix . '.incidents.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="array_values(array_filter([
                      ['kind' => 'period', 'allDates' => true, 'values' => $periodValues],
                      $campuses ? ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId] : null,
                      ['kind' => 'school', 'schools' => $schools, 'campusSource' => $campuses ? 'campus_id' : null,
                       'campusId' => $campuses ? $selectedCampusId : $userCampusId, 'selected' => $selectedSchoolId],
                      // Incident type: a whole group, or one type in its group's <optgroup>, or Other
                      // (what neither group lists) — on its own, as on the form.
                      ['kind' => 'select', 'name' => 'incident_type', 'label' => 'Incident type', 'selected' => $selectedType,
                       'options' => ['' => 'All']
                           + array_map(fn ($filter) => $filter['option'], \App\Models\Incident\Incident::typeGroupFilters())
                           + array_map(fn ($types) => array_combine($types, $types), \App\Models\Incident\Incident::typeGroups())
                           + [\App\Models\Incident\Incident::OTHER_TYPE => 'Other']],
                      ['kind' => 'select', 'name' => 'status', 'label' => 'Status',
                       'options' => ['' => 'All'] + array_combine(\App\Models\Incident\Incident::STATUSES, \App\Models\Incident\Incident::STATUSES), 'selected' => $selectedStatus],
                  ]))" />

    <details class="mb-6">
        <summary class="btn btn-secondary inline-flex cursor-pointer"><x-icon name="crisis_alert" class="text-red-600" /> Report an incident here</summary>
        <div class="mt-4">@include('incident._form', ['formAction' => route($routePrefix . '.incidents.store'), 'embedded' => true])</div>
    </details>
    <div id="incident-results" data-live-refresh class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3" data-incident-grid>
        @forelse ($incidents as $incident)
            <article class="card-flush overflow-hidden">
                <a href="{{ route($routePrefix . '.incidents.show', $incident) }}" class="block">
                    @if ($incident->photo_path)
                        <img src="{{ $incident->photo_url }}" alt="{{ $incident->photo_caption ?: $incident->hazard_type }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                    @else
                        <div class="flex aspect-[4/3] items-center justify-center bg-red-50 text-red-600"><x-icon name="crisis_alert" class="h-12 w-12" /><span class="sr-only">No photo uploaded</span></div>
                    @endif
                </a>
                <div class="space-y-3 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-600">{{ $incident->date_occurred->format('M d, Y') }}</p>
                        <x-badge :value="$incident->status" :map="['Active' => 'bg-red-100 text-red-700', 'Under Response' => 'bg-amber-100 text-amber-700', 'Resolved' => 'bg-green-100 text-green-700']" />
                    </div>
                    <h2 class="font-semibold"><a href="{{ route($routePrefix . '.incidents.show', $incident) }}">{{ $incident->hazard_type }}</a></h2>
                    <p class="text-xs text-gray-600">{{ $incident->campus->campus_name ?? '—' }} · {{ $incident->school->school_name ?? '—' }}</p>
                    @if ($incident->description)<p class="line-clamp-3 text-sm text-gray-700">{{ $incident->description }}</p>@endif
                    <div class="flex flex-wrap gap-4 border-t border-gray-100 pt-3 text-sm">
                        <a href="{{ route($routePrefix . '.incidents.show', $incident) }}" class="link">View</a>
                        <a href="{{ route($routePrefix . '.incidents.report', $incident) }}" data-confirm="Download a PDF report of this incident and its damage reports?" class="link-muted">Download PDF</a>
                    </div>
                </div>
            </article>
        @empty
            <div class="card sm:col-span-2 xl:col-span-3" data-empty-guidance>
                @if ($filtersApplied)
                    <p class="text-sm text-gray-600">No incidents match these filters.</p>
                    <p class="form-help">Set the filters above back to All to see every incident you can open.</p>
                @elseif ($routePrefix === 'admin')
                    <p class="text-sm text-gray-600">No incidents recorded yet.</p>
                    <p class="form-help">Incidents reported on any campus appear here. To report one yourself, use + Report incident at the top of the page.</p>
                @else
                    <p class="text-sm text-gray-600">{{ "You haven't reported any incidents yet." }}</p>
                    <p class="form-help">{{ "When a typhoon, earthquake, flood or fire affects your campus, use + Report incident at the top of the page to record it. Other coordinators' incidents are not shown to you." }}</p>
                @endif
            </div>
        @endforelse
    </div>
@endsection

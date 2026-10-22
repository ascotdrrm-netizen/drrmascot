@extends('layouts.app')

@section('title', $advisory->hazard_label . ' Advisory - ASCOT DRRMS')
@section('heading', $advisory->headline)
@section('subheading', $advisory->hazard_label . ' advisory · ' . $advisory->campus_label . ' · issued ' . $advisory->issued_at->format('M j, Y g:i A'))

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.advisories.index") }}'>Back to disaster advisories</x-back-link>
@endsection

@section('actions')
    @if ($advisory->canRecordIncident())
        <a href="{{ route("{$routePrefix}.incidents.create", ['advisory' => $advisory->advisory_id]) }}" class="btn btn-primary" data-record-incident>Record what happened</a>
    @endif
    <a href="{{ route("{$routePrefix}.advisories.memorandum", $advisory) }}" class="btn btn-secondary">Print advisory memo</a>
    @if ($canManage)
        <a href="{{ route("{$routePrefix}.advisories.edit", $advisory) }}" class="btn btn-secondary">Edit</a>
        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.advisories.destroy", $advisory) }}"
              data-confirm-form="This moves the advisory to Trash and takes it off the dashboards. Incidents recorded from it keep their link. You can restore it from Trash."
              data-confirm-title="Move to Trash?" data-confirm-button="Move to Trash" data-confirm-tone="danger" data-confirm-focus="cancel">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    @endif
@endsection

@section('content')
    @if ($advisory->status === 'Active' && ! $advisory->isLive())
        <p class="page-section rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Its valid-until time ({{ $advisory->valid_until->format('M j, Y g:i A') }}) has passed, so it no longer shows on the dashboards.
            @if ($canManage) Edit it to mark it Passed, or extend Valid until if the bulletin was renewed. @endif
        </p>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-card>
            <div class="card-header">
                <h2 class="card-title">Advisory</h2>
                @include('advisory._status-badge')
            </div>
            <dl class="detail-list">
                <div><dt class="text-gray-600">Hazard</dt><dd class="text-right font-medium text-gray-900">{{ $advisory->hazard_label }}</dd></div>
                <div><dt class="text-gray-600">Campus</dt><dd class="text-right font-medium text-gray-900">{{ $advisory->campus_label }}</dd></div>
                <div><dt class="text-gray-600">Source <x-info-tip term="Source" :text="config('glossary.advisory_source')" /></dt><dd class="text-right font-medium text-gray-900">{{ $advisory->source_label }}{{ $advisory->bulletin_reference ? ' · ' . $advisory->bulletin_reference : '' }}</dd></div>
                <div><dt class="text-gray-600">Issued</dt><dd class="text-right font-medium text-gray-900">{{ $advisory->issued_at->format('M d, Y g:i A') }}</dd></div>
                <div><dt class="text-gray-600">Expected to affect the campus</dt><dd class="text-right font-medium text-gray-900">{{ $advisory->expected_at?->format('M d, Y g:i A') ?? 'Not forecast' }}</dd></div>
                <div><dt class="text-gray-600">Valid until <x-info-tip term="Valid until" :text="config('glossary.advisory_valid_until')" /></dt><dd class="text-right font-medium text-gray-900">{{ $advisory->valid_until->format('M d, Y g:i A') }}</dd></div>
                <div><dt class="text-gray-600">Issued by</dt><dd class="text-right font-medium text-gray-900">{{ $advisory->created_by_name ?? '—' }}</dd></div>
            </dl>
            @unless ($canManage)
                @php $byCoordinator = $advisory->created_by_name && optional($advisory->creator)->role === 'Coordinator'; @endphp
                <p class="caption mt-4">Only {{ $byCoordinator ? $advisory->created_by_name . ' (who issued it) or the DRRMO' : 'the DRRMO' }} can change or delete this advisory.</p>
            @endunless
        </x-card>

        <x-card>
            <div class="card-header">
                <h2 class="card-title">Measurements</h2>
                <span class="caption">From the bulletin</span>
            </div>
            @php $lines = $advisory->measurementLines(); @endphp
            @if ($lines)
                <dl class="detail-list">
                    @foreach ($lines as $label => $value)
                        <div>
                            <dt class="text-gray-600">{{ $label }}
                                @if (config('glossary.measurement_terms')[$label] ?? null)
                                    <x-info-tip :term="$label" :text="config('glossary.measurement_terms')[$label]" />
                                @endif
                            </dt>
                            <dd class="text-right font-medium text-gray-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-gray-600">None entered.</p>
            @endif
        </x-card>
    </div>

    <x-card class="page-section mt-6">
        <div class="card-header"><h2 class="card-title">Instructions for students and staff</h2></div>
        <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $advisory->instructions }}</p>
    </x-card>

    <x-card flush class="mt-6" data-section="linked-incidents">
        <div class="card-header px-6 pt-6">
            <div>
                <h2 class="card-title">What happened</h2>
                <p class="caption leading-6">Incidents recorded from this advisory. The damage is recorded on each incident.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Date occurred</th>
                        <th class="table-th">Incident type</th>
                        <th class="table-th">Campus</th>
                        <th class="table-th">Status</th>
                        <th class="table-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($incidents as $incident)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $incident->date_occurred->format('M d, Y') }}</td>
                            <td class="table-td font-medium text-gray-900">{{ $incident->hazard_type }}</td>
                            <td class="table-td text-gray-600">{{ $incident->campus->campus_name ?? '—' }}</td>
                            <td class="table-td"><x-badge :value="$incident->status" /></td>
                            <td class="table-td text-right"><a href="{{ route("{$routePrefix}.incidents.show", $incident) }}" class="link">View</a></td>
                        </tr>
                    @empty
                        {{-- A coordinator sees only incidents they recorded, so "none" must say
                             "none of yours", and mention (by count only) any recorded by others. --}}
                        @php
                            $isAdminView = $routePrefix === 'admin';
                            $othersHint = $hiddenIncidentCount === 1
                                ? '1 incident recorded by another coordinator isn’t shown to you: an incident is visible only to whoever recorded it and the DRRMO.'
                                : $hiddenIncidentCount . ' incidents recorded by other coordinators aren’t shown to you: an incident is visible only to whoever recorded it and the DRRMO.';
                        @endphp
                        <x-empty-state :colspan="5" icon="incidents"
                                       :message="$isAdminView ? 'No incident recorded from this advisory.' : 'No incident you or the DRRMO recorded from this advisory.'"
                                       :hint="$hiddenIncidentCount > 0 ? $othersHint : ($advisory->canRecordIncident() ? 'If the event hits the campus, use Record what happened: the incident form opens with the hazard and campus filled in, linked to this advisory.' : 'A cancelled advisory can’t have an incident recorded from it.')" />
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($incidents->isNotEmpty() && $hiddenIncidentCount > 0)
            <p class="px-6 pb-4 pt-2 text-sm text-gray-600" data-hidden-incidents>
                {{ $hiddenIncidentCount === 1 ? '1 more, recorded by others, isn’t shown to you.' : $hiddenIncidentCount . ' more, recorded by others, aren’t shown to you.' }}
            </p>
        @endif
    </x-card>
@endsection

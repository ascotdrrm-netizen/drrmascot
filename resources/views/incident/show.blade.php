@extends('layouts.app')

@section('title', 'Incident: ' . $incident->hazard_type . ' - ASCOT DRRMS')
@section('heading', $incident->hazard_type . ' — ' . ($incident->campus->campus_name ?? 'Unknown campus'))

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.incidents.index") }}'>Back to incidents</x-back-link>
@endsection

@section('actions')
    <a href="{{ route("{$routePrefix}.incidents.report", $incident) }}" data-confirm="Download a PDF report of this incident and its damage reports?" class="btn btn-secondary">Download PDF</a>
    <a href="{{ route("{$routePrefix}.incidents.edit", $incident) }}" class="btn btn-primary">Edit incident</a>
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.incidents.destroy", $incident) }}" data-confirm-form="This moves the incident, with its damage reports and alerts, to Trash. You can restore it from there, photo included." data-confirm-title="Move to Trash?" data-confirm-button="Move to Trash" data-confirm-tone="danger" data-confirm-focus="cancel">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger">Delete incident</button>
    </form>
@endsection

@section('content')
    <x-card class="page-section max-w-3xl">
        @if ($incident->photo_path)
            <img src="{{ $incident->photo_url }}" alt="Incident photo" class="mb-4 w-full max-w-md rounded-lg">
            @if ($incident->photo_caption)<p class="mb-4 text-sm text-gray-600" data-photo-caption>{{ $incident->photo_caption }}</p>@endif
        @endif

        <dl class="detail-list">
            <div class="flex justify-between">
                <dt class="text-gray-600">Incident type <x-info-tip term="Incident type" :text="config('glossary.incident_hazard_type')" /></dt>
                <dd class="font-medium text-gray-900">{{ $incident->hazard_type }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-600">Date occurred</dt>
                <dd class="font-medium text-gray-900">{{ $incident->date_occurred->format('M d, Y') }}</dd>
            </div>
            <div class="flex items-center justify-between">
                <dt class="text-gray-600">Status <x-info-tip :term="$incident->status" :text="config('glossary.incident_status.' . $incident->status, '') . ' ' . config('glossary.incident_status_hint')" /></dt>
                <dd>
                    <x-badge :value="$incident->status" :map="[
                        'Active' => 'bg-red-100 text-red-700',
                        'Under Response' => 'bg-amber-100 text-amber-700',
                        'Resolved' => 'bg-green-100 text-green-700',
                    ]" />
                </dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-600">Campus</dt>
                <dd class="font-medium text-gray-900">{{ $incident->campus->campus_name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-gray-600">Recorded by school</dt>
                <dd class="text-right font-medium text-gray-900">{{ $incident->school->school_name ?? '—' }}</dd>
            </div>
            @if ($incident->advisory)
                <div class="flex justify-between gap-4" data-preceded-by>
                    <dt class="text-gray-600">Preceded by advisory</dt>
                    <dd class="text-right font-medium text-gray-900">
                        @if ($incident->advisory->trashed())
                            {{ $incident->advisory->headline }} <span class="font-normal text-gray-600">(in Trash)</span>
                        @else
                            <a href="{{ route("{$routePrefix}.advisories.show", $incident->advisory) }}" class="link">{{ $incident->advisory->headline }}</a>
                        @endif
                        <span class="block text-xs font-normal text-gray-600">{{ $incident->advisory->source_label }}, issued {{ $incident->advisory->issued_at->format('M j, Y g:i A') }}</span>
                    </dd>
                </div>
            @endif
        </dl>

        @if ($incident->description)
            <div class="mt-4 border-t border-gray-100 pt-4">
                <p class="text-sm leading-6 text-gray-600">Description</p>
                <p class="text-sm text-gray-900">{{ $incident->description }}</p>
            </div>
        @endif

        @if ($incident->hasPeopleCounts())
            {{-- People counts; a blank one shows "Not known", never 0. --}}
            <div class="mt-4 border-t border-gray-100 pt-4" data-people-counts>
                <p class="text-sm leading-6 text-gray-600">People</p>
                <dl class="mt-2 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-gray-100 bg-gray-100 sm:grid-cols-4">
                    @foreach (\App\Models\Incident\Incident::PEOPLE as $column => $label)
                        <div class="bg-white p-3">
                            <dt class="text-xs text-gray-500">{{ $label }}</dt>
                            <dd class="font-display text-2xl font-semibold {{ $incident->{$column} === null ? 'text-gray-400' : 'text-gray-900' }}">{{ $incident->{$column} ?? 'Not known' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        @if ($incident->hasLocation())
            <x-location-map :lat="$incident->latitude" :lng="$incident->longitude" class="mt-4" />
            <a href="{{ $incident->map_url }}" target="_blank" rel="noopener" class="link mt-4 inline-flex min-h-[2.75rem] sm:min-h-0 items-center gap-1 text-sm">
                <x-icon name="location_on" fill class="h-4 w-4" />
                View on Map
            </a>
        @endif
    </x-card>

    {{-- Measurements, as observed — the advisory's card, for a Disaster-related
         type only (a campus emergency or Other records none, so no card). --}}
    @if ($incident->measurementFields())
        <x-card class="page-section max-w-3xl" data-incident-measurements>
            <div class="card-header">
                <h2 class="card-title">Measurements</h2>
                <span class="caption">As observed</span>
            </div>
            @php $lines = $incident->measurementLines(); @endphp
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
    @endif

    @php
        $totalBuildings = $incident->damageReports->unique('building_id')->count();
        $totalCasualties = $incident->damageReports->sum('casualty_count');
        $totalCost = $incident->damageReports->sum('est_cost');
    @endphp

    <div class="section-header">
        <div>
            <h2 class="section-title">Damage Reports</h2>
            <p class="text-sm text-gray-600">{{ $totalBuildings }} {{ \Illuminate\Support\Str::plural('building', $totalBuildings) }} affected, {{ $totalCasualties }} {{ (int) $totalCasualties === 1 ? 'casualty' : 'casualties' }} in total, ₱{{ number_format($totalCost, 2) }} estimated cost.</p>
        </div>
        @if ($canManageDamageReports)
            <a href="{{ route("{$routePrefix}.incidents.damage-reports.create", $incident) }}" class="btn btn-secondary">+ Add damage report</a>
        @endif
    </div>

    <x-card flush class="mb-8">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Building/Area</th>
                        <th class="table-th">Damage level <x-info-tip term="Damage level" text="Minor: still usable. Major: part or all unusable until repaired. Total: destroyed or unsafe; must be rebuilt or replaced." /></th>
                        <th class="table-th num">Casualties <x-info-tip term="Casualties" :text="config('glossary.casualty_count')" /></th>
                        <th class="table-th num">Est. cost <x-info-tip term="Estimated cost" :text="config('glossary.est_cost')" /></th>
                        @if ($canManageDamageReports)
                            <th class="table-th actions">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($incident->damageReports as $report)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-900">{{ $report->building->building_name ?? '—' }}</td>
                            <td class="table-td">
                                <x-badge :value="$report->damage_level" :map="[
                                    'Minor' => 'bg-green-100 text-green-700',
                                    'Major' => 'bg-amber-100 text-amber-700',
                                    'Total' => 'bg-red-100 text-red-700',
                                ]" />
                            </td>
                            <td class="table-td num text-gray-600">{{ $report->casualty_count }}</td>
                            <td class="table-td num text-gray-600">{{ $report->est_cost !== null ? '₱' . number_format($report->est_cost, 2) : '—' }}</td>
                            @if ($canManageDamageReports)
                                <td class="table-td">
                                    <div class="table-actions">
                                        <a href="{{ route("{$routePrefix}.incidents.damage-reports.edit", [$incident, $report]) }}" class="link">Edit</a>
                                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.incidents.damage-reports.destroy", [$incident, $report]) }}" data-confirm-form="This permanently deletes the damage report for {{ $report->building->building_name ?? 'this building/area' }}. It cannot be undone." data-confirm-title="Delete damage report?" data-confirm-button="Delete permanently" data-confirm-tone="danger" data-confirm-focus="cancel">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="link-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <x-empty-state :colspan="$canManageDamageReports ? 5 : 4" icon="incidents" message="No damage reports recorded yet."
                                       hint="A damage report records what this incident did to one building or area: how bad, how many casualties, and a rough cost. Add one for each building or area affected, using the Add damage report button above." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="section-header">
        <div>
            <h2 class="section-title">Alert History</h2>
            <p class="guide-note">SMS alerts about this incident, sent by the DRRMO to everyone on the campus with a contact number.</p>
        </div>
        @if ($canSendAlerts)
            <a href="{{ route('admin.incidents.alerts.create', $incident) }}" class="btn btn-primary">Send Alert</a>
        @endif
    </div>

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Sent at</th>
                        <th class="table-th">Message</th>
                        <th class="table-th num">Recipients <x-info-tip term="Recipients" :text="config('glossary.recipients')" /></th>
                        <th class="table-th">Delivery status <x-info-tip term="Delivery status" text="Sent: reached every recipient. Partial: reached some, failed for others. Failed: could not be sent to anyone." /></th>
                        <th class="table-th">School</th>
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($incident->alerts as $alert)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $alert->sent_at->format('M d, Y g:i A') }}</td>
                            <td class="table-td text-gray-900">{{ $alert->message }}</td>
                            <td class="table-td num text-gray-600">{{ $alert->recipient_count }}</td>
                            <td class="table-td">
                                <x-badge :value="$alert->delivery_status" :map="[
                                    'Sent' => 'bg-green-100 text-green-700',
                                    'Partial' => 'bg-amber-100 text-amber-700',
                                    'Failed' => 'bg-red-100 text-red-700',
                                ]" />
                            </td>
                            {{-- Alerts have no school of their own: they're attributed via their incident. --}}
                            <td class="table-td text-gray-600">{{ $incident->school->school_name ?? '—' }}</td>
                            <td class="table-td text-right">
                                <a href="{{ route("{$routePrefix}.incidents.alerts.report", [$incident, $alert]) }}" data-confirm="Download this report?" class="link-muted">Download PDF</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8">
                                <x-empty-state icon="inbox" message="No alerts sent yet."
                                               :hint="$canSendAlerts ? 'Use Send Alert above to text everyone on this campus who has a contact number. A sent SMS cannot be recalled.' : 'Only the DRRMO can send SMS alerts about an incident.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

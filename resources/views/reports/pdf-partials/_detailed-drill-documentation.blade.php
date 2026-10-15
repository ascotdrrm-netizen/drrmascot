{{--
    Consolidated DOCUMENTATION of drills held in the period — documentation
    only, never After Activity Report content (that is its own report).
    $moduleData: ModuleReportController::drillSections() — rows, total, truncated, thumbnails.
--}}
<h2 class="section">Drill Documentation</h2>
<p class="caption">Drills held in the period, by the date held recorded in their documentation.</p>
@if ($moduleData['total'] === 0)
    <p class="empty">No drill documentation for this period.</p>
@else
    <p class="lead">{{ $moduleData['total'] }} documented {{ \Illuminate\Support\Str::plural('drill', $moduleData['total']) }}.</p>
    @if ($moduleData['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Http\Controllers\ModuleReportController::DRILL_SECTION_LIMIT, 'drill' => true])
    @endif

    @foreach ($moduleData['rows'] as $row)
        <div class="drill-entry" data-documentation-entry>
            <h3>{{ $row->drill->drill_type ?? 'Drill' }} drill — {{ $row->drill->campus->campus_name ?? '—' }} — held {{ $row->date_conducted->format('M j, Y') }}{{ $row->time_conducted ? ', ' . $row->time_conducted->format('g:i A') : '' }}</h3>
            <table class="fields">
                <tr>
                    <td><div class="field-label">Venue</div><div class="field-value">{{ $row->drill->venue ?? '—' }}</div></td>
                    <td><div class="field-label">Participants (approximate)</div><div class="field-value"><x-pdf.value :value="$row->participant_count" /></div></td>
                    <td><div class="field-label">Submitted by</div><div class="field-value"><x-pdf.value :value="$row->submitted_by_name" /></div></td>
                </tr>
                <tr><td class="field-wide" colspan="3"><div class="field-label">Narrative / observations</div><div class="field-text">{!! nl2br(e($row->narrative)) !!}</div></td></tr>
                @if ($row->notes)
                    <tr><td class="field-wide" colspan="3"><div class="field-label">Notes</div><div class="field-text">{!! nl2br(e($row->notes)) !!}</div></td></tr>
                @endif
            </table>
            @include('reports.pdf-partials._drill-section-files', ['thumbs' => $moduleData['thumbnails'][$row->getKey()] ?? []])
        </div>
    @endforeach
@endif

{{--
    Consolidated AFTER ACTIVITY REPORTS of drills held in the period — those
    reports only, never documentation content (that is its own report).
    $moduleData: ModuleReportController::drillSections() — rows, total, truncated, thumbnails.
--}}
<h2 class="section">After Activity Reports</h2>
<p class="caption">After Activity Reports of drills conducted in the period, by their date conducted.</p>
@if ($moduleData['total'] === 0)
    <p class="empty">No After Activity Reports for this period.</p>
@else
    <p class="lead">{{ $moduleData['total'] }} After Activity {{ \Illuminate\Support\Str::plural('Report', $moduleData['total']) }}.</p>
    @if ($moduleData['truncated'])
        @include('reports.pdf-partials._truncated', ['limit' => \App\Http\Controllers\ModuleReportController::DRILL_SECTION_LIMIT, 'drill' => true])
    @endif

    @foreach ($moduleData['rows'] as $row)
        @php
            $time = trim(($row->start_time?->format('g:i A') ?? '') . ($row->end_time ? ' – ' . $row->end_time->format('g:i A') : ''));
            $text = fn ($v) => $v !== null && $v !== '' ? nl2br(e($v)) : '<span class="not-specified">Not specified</span>';
        @endphp
        <div class="drill-entry" data-after-activity-entry>
            <h3>{{ $row->activity_title }} — {{ $row->drill->campus->campus_name ?? '—' }} — {{ $row->date_conducted->format('M j, Y') }}{{ $time !== '' ? ', ' . $time : '' }}</h3>
            <table class="fields">
                <tr>
                    <td><div class="field-label">Drill type</div><div class="field-value">{{ $row->drill->drill_type ?? '—' }}</div></td>
                    <td><div class="field-label">Participants</div><div class="field-value"><x-pdf.value :value="$row->totalParticipants()" /></div></td>
                    <td><div class="field-label">Response time (min)</div><div class="field-value"><x-pdf.value :value="$row->response_time_minutes" /></div></td>
                </tr>
                <tr><td class="field-wide" colspan="3"><div class="field-label">Activities conducted</div><div class="field-text">{!! $text($row->activities_conducted) !!}</div></td></tr>
                <tr><td class="field-wide" colspan="3"><div class="field-label">Results</div><div class="field-text">{!! $text($row->results) !!}</div></td></tr>
                <tr><td class="field-wide" colspan="3"><div class="field-label">Recommendations</div><div class="field-text">{!! $text($row->recommendations) !!}</div></td></tr>
            </table>
            @include('reports.pdf-partials._drill-section-files', ['thumbs' => $moduleData['thumbnails'][$row->getKey()] ?? []])
        </div>
    @endforeach
@endif

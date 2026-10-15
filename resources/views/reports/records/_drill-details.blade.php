{{-- The drill's schedule, shared by its documentation and After Activity Report PDFs. --}}
<h2 class="section">Drill</h2>
<table class="fields">
    <tr>
        <td><div class="field-label">Drill type</div><div class="field-value">{{ $drill->drill_type }}</div></td>
        <td><div class="field-label">Campus</div><div class="field-value"><x-pdf.value :value="$drill->campus->campus_name ?? null" /></div></td>
    </tr>
    <tr>
        <td><div class="field-label">Scheduled</div><div class="field-value">{{ $drill->scheduled_date->format('M j, Y') }} · {{ $drill->scheduled_time?->format('g:i A') ?? '—' }}</div></td>
        <td><div class="field-label">Venue</div><div class="field-value"><x-pdf.value :value="$drill->venue" /></div></td>
    </tr>
    <tr>
        <td class="field-wide" colspan="2"><div class="field-label">Partner agency</div><div class="field-value"><x-pdf.value :value="$drill->partner_agency" /></div></td>
    </tr>
</table>

{{--
    Prepared by: the personnel who generated the document (pre-filled), or
    the person the report names (e.g. a drill's submitting coordinator);
    $preparedByBlank leaves the name line empty to sign by hand.
    Noted by: the official set on the Settings page (App\Support\Settings),
    by default the DRRMO Administrator line with the name left for signing.
    Never the same person twice (thesis panel, Engineering): if the preparer
    is that official, the name line is left blank to be signed by hand.
    page-break-inside: avoid keeps the whole block on one page.
--}}
@php
    $notedByName = \App\Support\Settings::get('report.noted_by_name');
    $notedByPosition = \App\Support\Settings::get('report.noted_by_position');
    $samePerson = filled($notedByName) && filled($preparedByName ?? null)
        && mb_strtolower(trim($notedByName)) === mb_strtolower(trim($preparedByName));
@endphp
<div class="signatures">
    <table>
        <tr>
            <td>
                <div class="sig-heading">Prepared by:</div>
                <div class="sig-line"></div>
                @if ($preparedByBlank ?? false)
                    <div class="sig-name blank">Signature over printed name</div>
                @else
                    <div class="sig-name">{{ $preparedByName ?? 'Not specified' }}</div>
                @endif
                <div class="sig-role">@if (filled($preparedByRole ?? null)){{ $preparedByRole }}@else&nbsp;@endif</div>
                <div class="sig-date">Date: <span>&nbsp;</span></div>
            </td>
            <td>
                <div class="sig-heading">Noted by:</div>
                <div class="sig-line"></div>
                @if (filled($notedByName) && ! $samePerson)
                    <div class="sig-name">{{ $notedByName }}</div>
                @else
                    <div class="sig-name blank">Signature over printed name</div>
                @endif
                <div class="sig-role">{{ $notedByPosition }}</div>
                <div class="sig-date">Date: <span>&nbsp;</span></div>
            </td>
        </tr>
    </table>
</div>

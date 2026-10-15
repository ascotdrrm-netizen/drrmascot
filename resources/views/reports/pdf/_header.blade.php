{{--
    Official letterhead, repeated on every page (position: fixed in the top
    margin). Logos are embedded as base64 data URIs read from the real files
    in public/images — dompdf never fetches anything over HTTP.
    Campus line: the document's campus and its address from the campuses
    table, or every campus when the document covers all of them.
--}}
@php
    $logo = function (string $file) {
        $path = public_path('images/' . $file);
        return is_file($path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($path)) : null;
    };
    $campusLine = $docCampus
        ? trim($docCampus->campus_name . ($docCampus->address ? ' · ' . $docCampus->address : ''))
        : \App\Models\Campus::orderBy('campus_name')->get(['campus_name', 'address'])
            ->map(fn ($c) => $c->address ?: $c->campus_name)->implode('  ·  ');
    $ascot = $ascotLogo ?? $logo('ascot-logo.png');
    $drrm = $drrmLogo ?? $logo('drrm-logo.png');
@endphp
<div class="doc-header">
    <table>
        <tr>
            <td class="logo" style="text-align: left;">@if ($ascot)<img src="{{ $ascot }}" alt="ASCOT seal">@endif</td>
            <td class="center">
                <div class="lh-republic">Republic of the Philippines</div>
                <div class="lh-school">AURORA STATE COLLEGE OF TECHNOLOGY</div>
                <div class="lh-office">Disaster Risk Reduction and Management Office</div>
                <div class="lh-campus">{{ $campusLine }}</div>
            </td>
            <td class="logo" style="text-align: right;">@if ($drrm)<img src="{{ $drrm }}" alt="DRRM Office logo">@endif</td>
        </tr>
    </table>
    <div class="rule"></div>
    <div class="rule-thin"></div>
</div>

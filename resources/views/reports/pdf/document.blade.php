{{--
    The one document shell every PDF in the system extends.

    Child views set these PHP variables in a php block above their sections
    (Blade hands a child's variables to its layout automatically):
      $docTitle          report title (required)
      $docSubtitle       period label or record identifier
      $docCampus         App\Models\Campus|null  (one campus -> its name under "Campus covered")
      $docCampusLabel    "Campus covered" text, e.g. "All Campuses"
      $docReference      derived reference number, or null
      $docReferenceNote  shown instead when there is no reference number
      $docCompact        true for per-record reports: one-row metadata and
                         tighter spacing so a typical record fits on one
                         page (content is never cut — a long record still
                         flows onto page 2)
    and define a "content" section. Everything else (the ASCOT letterhead,
    metadata, signature block, footer with page X of Y) comes from here.

    Page setup (config/pdf.php): one paper size, portrait, and the official
    letterhead image (header, yellow footer band, watermark) full-bleed
    behind every page; content stays inside the box between its artwork.

    Optional overrides:
      $docStamp          short label printed in a box under the title (e.g. "For review")
      $docPreparedBy     ['name' => ?string, 'role' => ?string] for the signature's
                         "Prepared by" when it isn't the person generating the
                         PDF; a null name leaves a blank line to sign by hand
      $docShowMeta       false hides the metadata table (public notices)
      $docShowSignature  false hides the signature block
      $docFiguresNote    true prints, above the signatures, that the figures are as
                         recorded when generated (period and consolidated reports)
      $docFooterNote     replaces the footer's "internal use" line
      $docPageFlag       bold label printed in every page's footer (e.g. "FOR REVIEW")
      $docSchoolLabel    school shown next to the campus in the metadata block
                         (a record's school, or a report's school filter);
                         unset = no School cell (e.g. buildings, directories)
--}}
@php
    $user = Auth::user();
    $roleLabels = ['DRRMO Admin' => 'DRRMO Administrator', 'Coordinator' => 'Campus DRRM Coordinator'];
    $generatorName = $generatedByName ?? $user?->full_name;
    $generatorRole = $generatedByPosition ?? ($roleLabels[$user?->role] ?? $user?->role);
    $preparedByName = isset($docPreparedBy) ? ($docPreparedBy['name'] ?? null) : $generatorName;
    $preparedByRole = isset($docPreparedBy) ? ($docPreparedBy['role'] ?? null) : $generatorRole;
    $preparedByBlank = isset($docPreparedBy) && $preparedByName === null;
    $docCampus = $docCampus ?? null;
    $docCampusLabel = $docCampusLabel ?? ($docCampus?->campus_name ?? 'All Campuses');
    $docCompact = $docCompact ?? false;
    $docShowMeta = $docShowMeta ?? true;
    $docShowSignature = $docShowSignature ?? true;
    $docFiguresNote = $docFiguresNote ?? false;
    $generatedBy = ($generatorName ?? 'Not specified') . ($generatorRole ? ' — ' . $generatorRole : '');
    [$pageWidth, $pageHeight] = config('pdf.paper_inches');
    $margin = config('pdf.margins_inches');
    // Footer line: from the content box's bottom edge down to just above the
    // yellow band (0.02 in clear of it).
    $contentBottom = $pageHeight - $margin['bottom'];
    $footerHeight = round($pageHeight * config('pdf.footer_band_top') - 0.02 - $contentBottom, 3);
    $footerStyle = "bottom: -{$footerHeight}in; height: {$footerHeight}in;";
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $docTitle }}{{ isset($docSubtitle) ? ' — ' . $docSubtitle : '' }}</title>
    <style>
        @include('reports.pdf._style')
        @if ($docCompact)
            @include('reports.pdf._style-compact')
        @endif
        @yield('styles')

        /* Content box between the letterhead's header artwork and its footer band. */
        @page { margin: {{ $margin['top'] }}in {{ $margin['right'] }}in {{ $margin['bottom'] }}in {{ $margin['left'] }}in; }
        /* Letterhead: fixed (so dompdf repeats it on every page), offset by the
           margins so it bleeds to the paper's edges, stretched to the paper
           size like the memo template, and painted first — behind everything. */
        .letterhead { position: fixed; top: -{{ $margin['top'] }}in; left: -{{ $margin['left'] }}in; width: {{ $pageWidth }}in; height: {{ $pageHeight }}in; z-index: -10; }
        .letterhead img { width: {{ $pageWidth }}in; height: {{ $pageHeight }}in; }
    </style>
</head>
<body>
    {{-- Fixed elements must precede the content so dompdf repeats them on every page.
         The letterhead is referenced by file path (not a URL or data URI), so dompdf
         embeds the image once per document and reuses it on each page. --}}
    <div class="letterhead"><img src="{{ public_path(config('pdf.letterhead')) }}" alt=""></div>
    @include('reports.pdf._footer', ['footerNote' => $docFooterNote ?? null, 'pageFlag' => $docPageFlag ?? null, 'footerStyle' => $footerStyle])

    <div class="doc-title">{{ $docTitle }}</div>
    @isset($docSubtitle)
        <div class="doc-subtitle">{{ $docSubtitle }}</div>
    @endisset
    @isset($docStamp)
        <div class="doc-stamp-wrap"><span class="doc-stamp">{{ $docStamp }}</span></div>
    @endisset

    @php
        $metaReference = ! empty($docReference)
            ? '<div class="meta-value">' . e($docReference) . '</div>'
            : '<div class="meta-note">' . e($docReferenceNote ?? 'Not assigned') . '</div>';
    @endphp
    @php $hasSchool = isset($docSchoolLabel); @endphp
    @if ($docShowMeta)
    <table class="doc-meta">
        @if ($docCompact)
            {{-- Per-record reports: all items on one row to save height. --}}
            <tr>
                <td @if ($hasSchool) style="width: 20%;" @endif><div class="meta-label">Campus covered</div><div class="meta-value">{{ $docCampusLabel }}</div></td>
                @if ($hasSchool)
                    <td style="width: 20%;"><div class="meta-label">School</div><div class="meta-value">{{ $docSchoolLabel }}</div></td>
                @endif
                <td><div class="meta-label">Reference no.</div>{!! $metaReference !!}</td>
                <td><div class="meta-label">Generated</div><div class="meta-value">{{ now()->format('M j, Y · g:i A') }}</div></td>
                <td><div class="meta-label">Generated by</div><div class="meta-value">{{ $generatedBy }}</div></td>
            </tr>
        @else
            <tr>
                <td><div class="meta-label">Campus covered</div><div class="meta-value">{{ $docCampusLabel }}</div></td>
                <td><div class="meta-label">Reference no.</div>{!! $metaReference !!}</td>
                <td><div class="meta-label">Generated</div><div class="meta-value">{{ now()->format('F j, Y · g:i A') }}</div></td>
            </tr>
            <tr>
                @if ($hasSchool)
                    <td><div class="meta-label">School</div><div class="meta-value">{{ $docSchoolLabel }}</div></td>
                @endif
                <td colspan="{{ $hasSchool ? 2 : 3 }}"><div class="meta-label">Generated by</div><div class="meta-value">{{ $generatedBy }}</div></td>
            </tr>
        @endif
    </table>
    @endif

    @yield('content')

    @if ($docFiguresNote)
        <p class="caption" data-figures-note>Figures as recorded when this report was generated; later edits, deletions or late entries change them.</p>
    @endif

    @if ($docShowSignature)
        @include('reports.pdf._signature', ['preparedByName' => $preparedByName, 'preparedByRole' => $preparedByRole, 'preparedByBlank' => $preparedByBlank])
    @endif
</body>
</html>

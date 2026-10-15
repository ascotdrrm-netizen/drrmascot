{{--
    Repeated on every page (position: fixed), on one line just above the
    letterhead's yellow footer band — placed by document.blade.php from
    config/pdf.php. "Page X" is dompdf's per-page counter(page); "of Y" is
    $totalPages, computed by a throwaway first render
    (RendersPdfAssets::loadPdfViewWithPageCount()) because counter(pages)
    always resolves to 0 in this dompdf install.
--}}
<div class="doc-footer" style="{{ $footerStyle }}">
    <table>
        <tr>
            <td class="note">{{ $footerNote ?? 'ASCOT-DRRMS · For internal use of the ASCOT DRRM Office. Not for public distribution.' }}</td>
            @if (! empty($pageFlag))
                <td class="flag">{{ $pageFlag }}</td>
            @endif
            <td class="right">Page <span class="pagenum"></span> of {{ $totalPages ?? 1 }}</td>
        </tr>
    </table>
</div>

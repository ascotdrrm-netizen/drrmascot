{{--
    Shared document-metadata band. Callers pass:
    - metaReference: this record's own reference/document id (e.g. "BLDG-00028")
    - metaCampus: the campus name string
--}}
<div class="meta-strip">
    <table>
        <tr>
            <td>
                <div class="meta-label">Document Reference</div>
                <div class="meta-value">{{ $metaReference }}</div>
            </td>
            <td>
                <div class="meta-label">Campus</div>
                <div class="meta-value">{{ $metaCampus }}</div>
            </td>
            <td>
                <div class="meta-label">Date Generated</div>
                <div class="meta-value">{{ now()->format('F d, Y g:i A') }}</div>
            </td>
        </tr>
    </table>
</div>

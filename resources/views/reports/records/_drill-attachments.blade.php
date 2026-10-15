{{--
    A submission's files in its own PDF: every photo, two per row, shrunk for
    printing (App\Support\PdfImage) and flowing onto further pages as needed —
    a row is never split across a page. PDFs can't be embedded in a PDF here,
    so they are listed by name and size.
--}}
@if ($images->isNotEmpty())
    <h2 class="section">Photos ({{ $images->count() }})</h2>
    <table class="photos">
        @foreach ($images->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $photo)
                    <td><x-pdf.photo :src="$photo['src']" :caption="$photo['caption']" :max-width="240" :max-height="180" /></td>
                @endforeach
                @if ($pair->count() === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
@endif

@if ($documents->isNotEmpty())
    <h2 class="section">Attached documents</h2>
    <p class="caption">Kept with the record in ASCOT-DRRMS; not reproduced in this printout.</p>
    <table class="data">
        <thead><tr><th>File</th><th class="num" style="width: 18%;">Size</th></tr></thead>
        <tbody>
            @foreach ($documents as $file)
                <tr><td>{{ $file->original_name }}</td><td class="num">{{ $file->sizeLabel() }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

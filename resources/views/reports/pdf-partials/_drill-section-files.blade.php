{{--
    A drill's files inside a consolidated report: up to
    ModuleReportController::THUMBNAILS_PER_DRILL photos as a 2×2 block, then a
    note of how many more are in the drill's own PDF; PDFs listed by name.
--}}
@php
    $photoCount = $row->attachments->filter->isImage()->count();
    $documents = $row->attachments->reject->isImage();
@endphp
@if ($thumbs !== [])
    <table class="thumbs">
        @foreach (array_chunk($thumbs, 2) as $pair)
            <tr>
                @foreach ($pair as $thumb)
                    <td><x-pdf.photo :src="$thumb['src']" :max-width="200" :max-height="130" /></td>
                @endforeach
                @if (count($pair) === 1)<td></td>@endif
            </tr>
        @endforeach
    </table>
    @if ($photoCount > count($thumbs))
        <p class="caption">+{{ $photoCount - count($thumbs) }} more {{ \Illuminate\Support\Str::plural('photo', $photoCount - count($thumbs)) }} in this drill’s own printout.</p>
    @endif
@endif
@if ($documents->isNotEmpty())
    <p class="caption">Attached documents: {{ $documents->map(fn ($d) => $d->original_name . ' (' . $d->sizeLabel() . ')')->join('; ') }}</p>
@endif

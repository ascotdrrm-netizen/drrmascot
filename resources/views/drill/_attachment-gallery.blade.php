{{--
    Read-only list of one submission's files ($files): photos as thumbnails,
    PDFs as rows. Every link goes through the signed-in, campus-checked
    attachment route — the files themselves are on the private disk.
--}}
@php
    $photos = $files->filter->isImage();
    $documents = $files->reject->isImage();
@endphp
@if ($files->isNotEmpty())
    <div class="mt-4 border-t border-gray-100 pt-4" data-attachments>
        <p class="text-sm leading-6 text-gray-600">Attachments ({{ $files->count() }})</p>
        @if ($photos->isNotEmpty())
            <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                @foreach ($photos as $file)
                    <a href="{{ route("{$routePrefix}.drills.attachments.show", [$drill, $file]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-gray-100" title="{{ $file->original_name }}">
                        <img src="{{ route("{$routePrefix}.drills.attachments.show", [$drill, $file]) }}" alt="{{ $file->original_name }}" loading="lazy" class="aspect-square w-full object-cover">
                    </a>
                @endforeach
            </div>
        @endif
        @if ($documents->isNotEmpty())
            <ul class="mt-2 divide-y divide-gray-100 text-sm">
                @foreach ($documents as $file)
                    <li class="flex min-w-0 items-center justify-between gap-4 py-2">
                        <span class="min-w-0 truncate text-gray-900" title="{{ $file->original_name }}">{{ $file->original_name }}</span>
                        <span class="flex shrink-0 items-center gap-4">
                            <span class="caption">{{ $file->sizeLabel() }}</span>
                            <a href="{{ route("{$routePrefix}.drills.attachments.show", [$drill, $file]) }}?download=1" class="link">Download</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- Existing attachments of one submission, each removable (outside the main form: forms can't nest). --}}
@if ($attachments->isNotEmpty())
    <x-card class="mt-6 max-w-3xl" data-attachments-manage>
        <h2 class="card-title mb-4">Attached files ({{ $attachments->count() }})</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach ($attachments as $file)
                <li class="flex min-w-0 items-center justify-between gap-4 py-2">
                    <span class="flex min-w-0 items-center gap-4">
                        @if ($file->isImage())
                            <img src="{{ route("{$routePrefix}.drills.attachments.show", [$drill, $file]) }}" alt="" class="h-10 w-10 shrink-0 rounded-sm object-cover">
                        @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-sm bg-gray-100 text-xs font-semibold text-gray-600">PDF</span>
                        @endif
                        <a href="{{ route("{$routePrefix}.drills.attachments.show", [$drill, $file]) }}" target="_blank" rel="noopener" class="min-w-0 truncate text-gray-900 hover:text-accent-ink" title="{{ $file->original_name }}">{{ $file->original_name }}</a>
                    </span>
                    <span class="flex shrink-0 items-center gap-4">
                        <span class="caption">{{ $file->sizeLabel() }}</span>
                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.drills.attachments.destroy", [$drill, $file]) }}"
                              data-confirm-form="This permanently deletes {{ $file->original_name }}. It cannot be undone."
                              data-confirm-title="Remove file?" data-confirm-button="Remove" data-confirm-tone="danger" data-confirm-focus="cancel">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="link-danger">Remove</button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif

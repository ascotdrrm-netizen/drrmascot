@props(['group'])

<div class="inline-flex items-center overflow-hidden rounded-full border border-gray-200 bg-white">
    <button type="button" data-view-toggle="{{ $group }}" data-view="grid"
            class="flex h-11 w-11 items-center justify-center text-gray-600 transition hover:bg-gray-50 sm:h-9 sm:w-9"
            title="Grid view" aria-label="Grid view">
        <x-icon name="grid_view" class="h-4 w-4" />
    </button>
    <button type="button" data-view-toggle="{{ $group }}" data-view="table"
            class="flex h-11 w-11 items-center justify-center text-gray-600 transition hover:bg-gray-50 sm:h-9 sm:w-9"
            title="Table view" aria-label="Table view">
        <x-icon name="table_rows" class="h-4 w-4" />
    </button>
</div>

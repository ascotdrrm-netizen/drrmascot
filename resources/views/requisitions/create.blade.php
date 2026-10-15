@extends('layouts.app')

@section('title', 'Request Supplies - ASCOT DRRMS')
@section('heading', 'Request Supplies')

@section('breadcrumb')
    <x-back-link href="{{ route('coordinator.requisitions.index') }}">Back to requisitions</x-back-link>
@endsection

@section('content')
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('coordinator.requisitions.store') }}" class="space-y-6" enctype="multipart/form-data">
            @csrf

            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Catalog items</label>
                <div class="space-y-4">
                    @foreach ($suppliesByCategory as $category => $items)
                        <div>
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $category }}</p>
                            <div class="space-y-1">
                                @foreach ($items as $supply)
                                    @php
                                        $isChecked = in_array((string) $supply->supply_id, old('catalog_items', []));
                                        $oldQuantity = old("catalog_quantities.{$supply->supply_id}");
                                    @endphp
                                    <div class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-gray-50">
                                        <input type="checkbox" id="catalog_{{ $supply->supply_id }}" name="catalog_items[]" value="{{ $supply->supply_id }}"
                                               class="catalog-item-checkbox h-4 w-4 rounded-sm border-gray-300 text-green-600 focus:ring-green-500"
                                               data-quantity-target="catalog_quantity_{{ $supply->supply_id }}"
                                               @checked($isChecked)>
                                        <label for="catalog_{{ $supply->supply_id }}" class="flex-1 text-sm text-gray-700">{{ $supply->item_name }}</label>
                                        <input type="number" id="catalog_quantity_{{ $supply->supply_id }}" name="catalog_quantities[{{ $supply->supply_id }}]"
                                               min="1" step="1" placeholder="Qty" value="{{ $oldQuantity }}" @unless($isChecked) disabled @endunless
                                               class="w-20 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700">Custom items (not in catalog)</label>
                    <button type="button" id="add-custom-item" class="text-sm font-medium text-green-700 hover:text-green-800">+ Add custom item</button>
                </div>
                <div id="custom-items-container" class="space-y-2">
                    @foreach (old('custom_items', []) as $index => $row)
                        <div class="custom-item-row flex items-center gap-3">
                            <input type="text" name="custom_items[{{ $index }}][name]" maxlength="150" placeholder="Item name"
                                   value="{{ $row['name'] ?? '' }}"
                                   class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                            <input type="number" name="custom_items[{{ $index }}][quantity]" min="1" step="1" placeholder="Qty"
                                   value="{{ $row['quantity'] ?? '' }}"
                                   class="w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                            <button type="button" class="remove-custom-item text-sm font-medium text-red-600 hover:text-red-700">Remove</button>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label for="letter" class="mb-1 block text-sm font-medium text-gray-700">Supporting letter (optional)</label>
                <input type="file" id="letter" name="letter" accept=".pdf,.doc,.docx"
                       class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-green-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-green-700 hover:file:bg-green-100">
                <p class="mt-1 text-xs text-gray-500">PDF or Word document, max 10MB.</p>
            </div>

            <button type="submit" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">
                Submit requisition
            </button>
        </form>
    </x-card>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.catalog-item-checkbox').forEach(function (checkbox) {
                    var quantityInput = document.getElementById(checkbox.dataset.quantityTarget);

                    if (! quantityInput) {
                        return;
                    }

                    checkbox.addEventListener('change', function () {
                        quantityInput.disabled = ! checkbox.checked;

                        if (! checkbox.checked) {
                            quantityInput.value = '';
                        }
                    });
                });

                var container = document.getElementById('custom-items-container');
                var addButton = document.getElementById('add-custom-item');
                var nextIndex = container.querySelectorAll('.custom-item-row').length;

                function attachRemoveHandler(row) {
                    var removeButton = row.querySelector('.remove-custom-item');
                    removeButton.addEventListener('click', function () {
                        row.remove();
                    });
                }

                container.querySelectorAll('.custom-item-row').forEach(attachRemoveHandler);

                addButton.addEventListener('click', function () {
                    var row = document.createElement('div');
                    row.className = 'custom-item-row flex items-center gap-3';
                    row.innerHTML =
                        '<input type="text" name="custom_items[' + nextIndex + '][name]" maxlength="150" placeholder="Item name" ' +
                        'class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">' +
                        '<input type="number" name="custom_items[' + nextIndex + '][quantity]" min="1" step="1" placeholder="Qty" ' +
                        'class="w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">' +
                        '<button type="button" class="remove-custom-item text-sm font-medium text-red-600 hover:text-red-700">Remove</button>';

                    container.appendChild(row);
                    attachRemoveHandler(row);
                    nextIndex++;
                });
            });
        </script>
    @endpush
@endsection

@php $isEdit = isset($supply); @endphp

<x-card class="max-w-xl">
    <form method="POST" action="{{ $formAction }}" class="space-y-4">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div>
            <label for="item_name" class="mb-1 block text-sm font-medium text-gray-700">Item name</label>
            <input type="text" id="item_name" name="item_name" maxlength="100"
                   value="{{ old('item_name', $supply->item_name ?? '') }}" required
                   class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
        </div>

        <div>
            <label for="category" class="mb-1 block text-sm font-medium text-gray-700">Category</label>
            <select id="category" name="category" required
                    class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                @foreach (['First Aid', 'TLS Kits', 'Food', 'Other'] as $cat)
                    <option value="{{ $cat }}" @selected(old('category', $supply->category ?? '') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="quantity_available" class="mb-1 block text-sm font-medium text-gray-700">Quantity available</label>
            <input type="number" id="quantity_available" name="quantity_available" min="0" step="1"
                   value="{{ old('quantity_available', $supply->quantity_available ?? 0) }}" required
                   class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
        </div>

        <div>
            <label for="reorder_level" class="mb-1 block text-sm font-medium text-gray-700">Reorder level</label>
            <input type="number" id="reorder_level" name="reorder_level" min="0" step="1"
                   value="{{ old('reorder_level', $supply->reorder_level ?? 0) }}" required
                   class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
        </div>

        <button type="submit" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">
            {{ $isEdit ? 'Update supply item' : 'Add supply item' }}
        </button>
    </form>
</x-card>

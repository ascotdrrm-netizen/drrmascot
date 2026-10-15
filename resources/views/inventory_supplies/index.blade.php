@extends('layouts.app')

@section('title', 'Inventory Supplies - ASCOT DRRMS')
@section('heading', 'Inventory Supplies')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@if ($routePrefix === 'admin')
    @section('actions')
        @if ($trashedCount > 0)
            <a href="{{ route('admin.inventory-supplies.trash') }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">View Trash ({{ $trashedCount }})</a>
        @endif
        <a href="{{ route('admin.inventory-supplies.report') }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Download Report</a>
        <a href="{{ route('admin.inventory-supplies.create') }}" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">+ Add supply item</a>
    @endsection
@endif

@section('content')
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quantity available</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Reorder level</th>
                        @if ($routePrefix === 'admin')
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($supplies as $supply)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900">{{ $supply->item_name }}</span>
                                    @if ($supply->isBelowReorderLevel())
                                        <x-badge value="Low stock" :map="['Low stock' => 'bg-red-100 text-red-700']" />
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->category }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->quantity_available }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->reorder_level }}</td>
                            @if ($routePrefix === 'admin')
                                <td class="px-4 py-3 text-sm">
                                    <div class="flex items-center gap-3">
                                        <a href="{{ route('admin.inventory-supplies.edit', $supply) }}" class="font-medium text-green-700 hover:text-green-800">Edit</a>
                                        <form method="POST" action="{{ route('admin.inventory-supplies.destroy', $supply) }}" onsubmit="return confirm('Delete this supply item?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-medium text-red-600 hover:text-red-700">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $routePrefix === 'admin' ? 5 : 4 }}" class="px-4 py-6 text-center text-sm text-gray-500">No supply items recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

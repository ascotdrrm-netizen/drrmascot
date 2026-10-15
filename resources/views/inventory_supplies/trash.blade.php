@extends('layouts.app')

@section('title', 'Inventory Supplies Trash - ASCOT DRRMS')
@section('heading', 'Inventory Supplies — Trash')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.inventory-supplies.index") }}'>Back to inventory supplies</x-back-link>
@endsection

@section('content')
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Item</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quantity available</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Deleted at</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($supplies as $supply)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $supply->item_name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->category }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->quantity_available }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $supply->deleted_at->format('M d, Y g:i A') }}</td>
                            <td class="px-4 py-3 text-sm">
                                <form method="POST" action="{{ route('admin.inventory-supplies.restore', $supply->supply_id) }}">
                                    @csrf
                                    <button type="submit" class="font-medium text-green-700 hover:text-green-800">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">Trash is empty.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

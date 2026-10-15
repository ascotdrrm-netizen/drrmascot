@extends('layouts.app')

@section('title', 'Requisitions Trash - ASCOT DRRMS')
@section('heading', 'Requisitions — Trash')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.requisitions.index") }}'>Back to requisitions</x-back-link>
@endsection

@section('content')
    @if ($routePrefix === 'admin')
        <form method="GET" action="{{ route('admin.requisitions.trash') }}" class="mb-4 flex items-center gap-2">
            <label for="campus_id" class="text-sm font-medium text-gray-700">Filter by campus:</label>
            <select name="campus_id" id="campus_id" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                <option value="">All campuses</option>
                @foreach ($campuses as $campus)
                    <option value="{{ $campus->campus_id }}" @selected((string) $selectedCampusId === (string) $campus->campus_id)>
                        {{ $campus->campus_name }}
                    </option>
                @endforeach
            </select>
        </form>
    @endif

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date requested</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Items</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        @if ($routePrefix === 'admin')
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Campus</th>
                        @endif
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Deleted at</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($requisitions as $requisition)
                        @php
                            $itemNames = $requisition->items->map->itemDisplayName();
                            $itemSummary = $itemNames->count() > 1
                                ? $itemNames->first() . ' and ' . ($itemNames->count() - 1) . ' more'
                                : ($itemNames->first() ?? '—');
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $requisition->date_requested->format('M d, Y') }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900" title="{{ $itemNames->implode(', ') }}">{{ $itemSummary }}</td>
                            <td class="px-4 py-3 text-sm">
                                <x-badge :value="$requisition->status" :map="[
                                    'Pending' => 'bg-amber-100 text-amber-700',
                                    'Approved' => 'bg-blue-100 text-blue-700',
                                    'Dispatched' => 'bg-green-100 text-green-700',
                                    'Denied' => 'bg-red-100 text-red-700',
                                ]" />
                            </td>
                            @if ($routePrefix === 'admin')
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $requisition->campus->campus_name ?? '—' }}</td>
                            @endif
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $requisition->deleted_at->format('M d, Y g:i A') }}</td>
                            <td class="px-4 py-3 text-sm">
                                <form method="POST" action="{{ route("{$routePrefix}.requisitions.restore", $requisition->requisition_id) }}">
                                    @csrf
                                    <button type="submit" class="font-medium text-green-700 hover:text-green-800">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $routePrefix === 'admin' ? 6 : 5 }}" class="px-4 py-6 text-center text-sm text-gray-500">Trash is empty.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

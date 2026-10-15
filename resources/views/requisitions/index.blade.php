@extends('layouts.app')

@section('title', 'Requisitions - ASCOT DRRMS')
@section('heading', 'Requisitions')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('actions')
    @if ($trashedCount > 0)
        <a href="{{ route("{$routePrefix}.requisitions.trash") }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">View Trash ({{ $trashedCount }})</a>
    @endif
    @if ($routePrefix === 'coordinator')
        <a href="{{ route('coordinator.requisitions.create') }}" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">+ Request Supplies</a>
    @endif
@endsection

@section('content')
    <form method="GET" action="{{ route("{$routePrefix}.requisitions.index") }}" class="mb-4 flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
            <label for="status" class="text-sm font-medium text-gray-700">Status:</label>
            <select name="status" id="status" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                <option value="">All</option>
                @foreach (['Pending', 'Approved', 'Dispatched', 'Denied'] as $statusOption)
                    <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption)>{{ $statusOption }}</option>
                @endforeach
            </select>
        </div>

        @if ($routePrefix === 'admin')
            <div class="flex items-center gap-2">
                <label for="campus_id" class="text-sm font-medium text-gray-700">Campus:</label>
                <select name="campus_id" id="campus_id" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-green-500 focus:outline-hidden focus:ring-2 focus:ring-green-500">
                    <option value="">All campuses</option>
                    @foreach ($campuses as $campus)
                        <option value="{{ $campus->campus_id }}" @selected((string) $selectedCampusId === (string) $campus->campus_id)>
                            {{ $campus->campus_name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <a href="{{ route("{$routePrefix}.requisitions.export", request()->query()) }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Export to Excel</a>
    </form>

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
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route("{$routePrefix}.requisitions.show", $requisition) }}" title="{{ $itemNames->implode(', ') }}" class="font-medium text-gray-900 hover:text-green-700">{{ $itemSummary }}</a>
                            </td>
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
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route("{$routePrefix}.requisitions.show", $requisition) }}" class="font-medium text-green-700 hover:text-green-800">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $routePrefix === 'admin' ? 5 : 4 }}" class="px-4 py-6 text-center text-sm text-gray-500">No requisitions recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

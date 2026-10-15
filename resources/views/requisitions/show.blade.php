@extends('layouts.app')

@section('title', 'Requisition - ASCOT DRRMS')
@section('heading', 'Requisition #' . $requisition->requisition_id)

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.requisitions.index") }}'>Back to requisitions</x-back-link>
@endsection

@if ($canAccessLetter)
    @section('actions')
        <a href="{{ route("{$routePrefix}.requisitions.report", $requisition) }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Download Report</a>
    @endsection
@endif

@section('content')
    <x-card class="mb-6 max-w-xl">
        <p class="mb-3 text-sm font-medium text-gray-700">Items requested</p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Item</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quantity</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($requisition->items as $item)
                        <tr>
                            <td class="px-3 py-2 text-sm font-medium text-gray-900">{{ $item->itemDisplayName() }}</td>
                            <td class="px-3 py-2 text-sm text-gray-500">{{ $item->quantity_requested }}</td>
                            <td class="px-3 py-2 text-sm">
                                @if ($item->isCustomItem())
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Not in inventory catalog</span>
                                @else
                                    <span class="text-gray-500">Catalog</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card class="max-w-xl">
        <dl class="mb-6 space-y-3 text-sm">
            <div class="flex items-center justify-between">
                <dt class="text-gray-500">Status</dt>
                <dd>
                    <x-badge :value="$requisition->status" :map="[
                        'Pending' => 'bg-amber-100 text-amber-700',
                        'Approved' => 'bg-blue-100 text-blue-700',
                        'Dispatched' => 'bg-green-100 text-green-700',
                        'Denied' => 'bg-red-100 text-red-700',
                    ]" />
                </dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Campus</dt>
                <dd class="font-medium text-gray-900">{{ $requisition->campus->campus_name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Date requested</dt>
                <dd class="font-medium text-gray-900">{{ $requisition->date_requested->format('M d, Y') }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Submitted by</dt>
                <dd class="font-medium text-gray-900">{{ $requisition->submittedBy->full_name ?? '—' }}</dd>
            </div>
        </dl>

        @if ($canAccessLetter)
            <div class="mb-6 border-t border-gray-100 pt-4">
                <p class="mb-2 text-sm font-medium text-gray-700">Supporting letter</p>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($requisition->hasLetter())
                        <a href="{{ route("{$routePrefix}.requisitions.letter.download", $requisition) }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Download Letter</a>
                    @else
                        <span class="text-sm text-gray-500">No letter attached.</span>
                    @endif

                    @if ($canEditLetter)
                        <a href="{{ route("{$routePrefix}.requisitions.letter.edit", $requisition) }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                            {{ $requisition->hasLetter() ? 'Replace Letter' : 'Add Letter' }}
                        </a>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            @if ($routePrefix === 'coordinator' && $requisition->status === 'Pending')
                <form method="POST" action="{{ route('coordinator.requisitions.destroy', $requisition) }}" onsubmit="return confirm('Cancel this requisition?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center rounded-full border border-red-200 px-5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50">Cancel requisition</button>
                </form>
            @endif

            @if ($routePrefix === 'admin' && $requisition->status === 'Pending')
                <form method="POST" action="{{ route('admin.requisitions.status', $requisition) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="Approved">
                    <button type="submit" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">Approve</button>
                </form>
                <form method="POST" action="{{ route('admin.requisitions.status', $requisition) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="Denied">
                    <button type="submit" class="inline-flex items-center justify-center rounded-full border border-red-200 px-5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50">Deny</button>
                </form>
            @endif

            @if ($routePrefix === 'admin' && $requisition->status === 'Approved')
                <form method="POST" action="{{ route('admin.requisitions.status', $requisition) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="Dispatched">
                    <button type="submit" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">Mark Dispatched</button>
                </form>
                <form method="POST" action="{{ route('admin.requisitions.status', $requisition) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="Denied">
                    <button type="submit" class="inline-flex items-center justify-center rounded-full border border-red-200 px-5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50">Deny</button>
                </form>
            @endif
        </div>
    </x-card>
@endsection

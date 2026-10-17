{{-- One report in a list: what, where, when, and its status in words. --}}
@php
    $statusTone = [
        'Submitted' => 'border-gray-200 text-gray-700',
        'Verified' => 'border-blue-200 bg-blue-50 text-blue-700',
        'Responding' => 'border-amber-200 bg-amber-50 text-amber-700',
        'Resolved' => 'border-green-200 bg-green-50 text-green-700',
        'Rejected' => 'border-red-200 bg-red-50 text-red-700',
    ];
@endphp
<a href="{{ route('app.reports.show', $report['id']) }}" class="flex items-center gap-3 p-4">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-700"><x-icon :name="$report['icon']" /></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm font-semibold">{{ $report['category'] }}</span>
        <span class="block truncate text-xs text-gray-600">{{ $report['place'] }} · {{ $report['when']->diffForHumans() }}</span>
    </span>
    <span class="chip shrink-0 {{ $statusTone[$report['status']] ?? $statusTone['Submitted'] }}">{{ $report['status'] }}</span>
</a>

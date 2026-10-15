{{--
    A Trash row's Removal cell: when the record will be permanently removed,
    or why it is kept (a building that damage reports point at). Rendered only
    while the purge is running — see x-trash-retention-note.
--}}
@props(['record'])

@php
    $reason = \App\Support\TrashPurge::blockReason($record);
    $at = \App\Support\TrashPurge::removesAt($record);
    $daysLeft = (int) ceil(now()->diffInSeconds($at) / 86400);
@endphp

<td class="table-td" data-removal>
    @if ($reason)
        <span class="text-amber-800" data-removal-kept>{{ $reason }}</span>
    @elseif ($daysLeft <= 0)
        <span class="whitespace-nowrap font-medium text-red-800">Removes at the next run</span>
    @else
        <span class="whitespace-nowrap text-gray-900">Removes in {{ $daysLeft }} {{ \Illuminate\Support\Str::plural('day', $daysLeft) }}</span>
        <span class="block whitespace-nowrap text-xs text-gray-600">{{ $at->format('M j, Y') }}</span>
    @endif
</td>

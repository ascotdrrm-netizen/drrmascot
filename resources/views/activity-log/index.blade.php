@extends('layouts.app')

@section('title', 'Activity Log - ASCOT DRRMS')
@section('heading', $personnel === null ? 'My Activity Log' : 'Activity Log')
@section('subheading', $personnel === null
    ? 'Everything you have done in the system — only your own entries.'
    : 'Every sign-in, change and download in the system, newest first. Entries cannot be edited or deleted.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('actions')
    <a href="{{ route("{$routePrefix}.activity-log.report", request()->query()) }}" data-confirm="Download this report?" class="btn btn-secondary">Export Report</a>
@endsection

@section('content')
    @php
        $actionLabels = \App\Models\ActivityLog::ACTION_LABELS;
        $actionBadgeMap = [
            'Login' => 'bg-green-100 text-green-800',
            'Created' => 'bg-green-100 text-green-800',
            'Updated' => 'bg-blue-100 text-blue-700',
            'Deleted' => 'bg-red-100 text-red-700',
            'Failed Login' => 'bg-red-100 text-red-700',
            'Logout' => 'bg-gray-100 text-gray-700',
            'Restored' => 'bg-gray-100 text-gray-700',
            'Permanently Deleted' => 'bg-red-100 text-red-700',
            'Data Privacy Denied' => 'bg-red-100 text-red-700',
            'Records Cleared' => 'bg-red-100 text-red-700',
            'Report Generated' => 'bg-blue-100 text-blue-700',
            'Report Exported' => 'bg-blue-100 text-blue-700',
            'Password Reset Requested' => 'bg-amber-100 text-amber-700',
            'Password Reset Completed' => 'bg-green-100 text-green-700',
            'Drill Scheduled' => 'bg-blue-100 text-blue-700',
            'Drill Acknowledged' => 'bg-indigo-100 text-indigo-700',
            'Drill Submitted' => 'bg-amber-100 text-amber-700',
            'Drill Returned' => 'bg-red-100 text-red-700',
            'Drill Completed' => 'bg-green-100 text-green-700',
        ];
    @endphp

    {{-- From, To (free dates, kept: no quarter picker here), User (Admin only: a
         coordinator's log is always just their own entries), Action. --}}
    <x-filter-bar :action="route($routePrefix . '.activity-log.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :controls="array_values(array_filter([
                      ['kind' => 'date', 'name' => 'date_from', 'label' => 'From', 'value' => $dateFrom],
                      ['kind' => 'date', 'name' => 'date_to', 'label' => 'To', 'value' => $dateTo],
                      $personnel !== null ? ['kind' => 'select', 'name' => 'personnel_id', 'label' => 'User', 'selected' => $selectedPersonnelId,
                          'options' => ['' => 'All', 'system' => 'System (no user)', 'deleted' => 'Deleted accounts'] + $personnel->pluck('full_name', 'personnel_id')->all()] : null,
                      ['kind' => 'select', 'name' => 'action', 'label' => 'Action', 'options' => ['' => 'All'] + $actionLabels, 'selected' => $selectedAction],
                  ]))" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Timestamp</th>
                        <th class="table-th">User</th>
                        <th class="table-th">Action <x-info-tip term="Action" text="What was done. Report Generated: a consolidated report was viewed; Report Exported: a report was downloaded; Data Privacy Denied: a wrong Data Privacy Password was entered on the Backup page." /></th>
                        <th class="table-th">Description</th>
                        <th class="table-th">IP Address <x-info-tip term="IP Address" text="The network address the action came from. It helps trace sign-ins from unexpected places." /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $log->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                            <td class="table-td text-gray-900">{{ $log->actor_label }}</td>
                            <td class="table-td">
                                <x-badge :value="$actionLabels[$log->action] ?? $log->action" :map="$actionBadgeMap" />
                            </td>
                            <td class="table-td text-gray-700">{{ $log->description }}</td>
                            <td class="table-td text-gray-600">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        @php $filtered = $filtersApplied; @endphp
                        @if ($filtered)
                            <x-empty-state colspan="5" icon="activity" message="No activity matches these filters." hint="Clear the filters above to see every entry." />
                        @elseif ($personnel === null)
                            <x-empty-state colspan="5" icon="activity" message="You have no recorded activity yet." hint="Each time you sign in, or record, change or delete something, an entry appears here." />
                        @else
                            <x-empty-state colspan="5" icon="activity" message="No activity recorded yet." hint="Every sign-in, change and download by anyone in the system is recorded here." />
                        @endif
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
@endsection

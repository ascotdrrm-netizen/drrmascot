@extends('layouts.app')

@section('title', 'Disaster Advisories Trash - ASCOT DRRMS')
@section('heading', 'Disaster Advisories — Trash')
@section('subheading', $isAdmin
    ? 'Deleted advisories wait here until someone restores them. A deleted advisory never shows on the dashboards.'
    : 'Advisories you issued and then deleted wait here until you restore them. A deleted advisory never shows on the dashboards.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.advisories.index") }}'>Back to disaster advisories</x-back-link>
@endsection

@section('content')
    @php $retentionRunning = \App\Support\TrashPurge::isRunning(); @endphp
    <x-trash-retention-note />

    {{-- Summary only: no controls on a list that is usually empty. --}}
    <x-filter-bar :summary="$filterSummary" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Hazard</th>
                        <th class="table-th">Headline</th>
                        @if ($isAdmin)
                            <th class="table-th">Campus</th>
                        @endif
                        <th class="table-th">Deleted at</th>
                        @if ($retentionRunning)
                            <th class="table-th">Removal</th>
                        @endif
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($advisories as $advisory)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td text-gray-600">{{ $advisory->issued_at->format('M d, Y g:i A') }}</td>
                            <td class="table-td font-medium text-gray-900">{{ $advisory->hazard_label }}</td>
                            <td class="table-td text-gray-900">{{ $advisory->headline }}</td>
                            @if ($isAdmin)
                                <td class="table-td text-gray-600">{{ $advisory->campus_label }}</td>
                            @endif
                            <td class="table-td text-gray-600">{{ $advisory->deleted_at->format('M d, Y g:i A') }}</td>
                            @if ($retentionRunning)
                                <x-trash-removal-cell :record="$advisory" />
                            @endif
                            <td class="table-td text-right">
                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.advisories.restore", $advisory->advisory_id) }}">
                                    @csrf
                                    <button type="submit" class="link">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="($isAdmin ? 6 : 5) + ($retentionRunning ? 1 : 0)" icon="trash" message="Trash is empty."
                                       :hint="$isAdmin ? 'When an advisory is deleted it comes here, and can be restored from here.' : 'When you delete an advisory you issued, it comes here, and you can restore it from here.'" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

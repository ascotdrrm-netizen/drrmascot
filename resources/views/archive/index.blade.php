@extends('layouts.app')

@section('title', 'Archive - ASCOT DRRMS')
@section('heading', 'Archive')
@section('subheading', 'Everything deleted, in one place. Restore brings a record back exactly as it was.')

@section('content')
    {{-- Filters: what kind, which campus (Admin), when it was deleted. --}}
    <form method="GET" action="{{ route("{$routePrefix}.archive.index") }}" class="filter-bar" data-archive-filters>
        <div class="filter-fields">
            <div class="filter-field">
                <label for="type" class="filter-label">Type</label>
                <select id="type" name="type" class="form-select-inline">
                    <option value="">All types</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($campuses)
                <div class="filter-field">
                    <label for="campus" class="filter-label">Campus</label>
                    <select id="campus" name="campus" class="form-select-inline">
                        <option value="">All campuses</option>
                        @foreach ($campuses as $campus)
                            <option value="{{ $campus->campus_id }}" @selected($campusId === $campus->campus_id)>{{ $campus->campus_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="filter-field">
                <label for="period" class="filter-label">Deleted</label>
                <select id="period" name="period" class="form-select-inline">
                    <option value="">Any time</option>
                    @foreach ($periods as $key => $label)
                        <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply filters</button>
        </div>
        <p class="filter-meta">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('record', $rows->count()) }} in the archive.</p>
    </form>

    <div class="card-flush">
        <div class="table-wrap">
            <table class="data-table" data-archive-table>
                <thead>
                    <tr>
                        <th class="table-th">Type</th>
                        <th class="table-th">Record</th>
                        <th class="table-th">Campus</th>
                        <th class="table-th">Deleted</th>
                        @if ($retentionRunning)<th class="table-th">Removal</th>@endif
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-archive-row="{{ $row['kind'] }}">
                            <td class="table-td"><span class="inline-flex items-center gap-2 text-gray-600"><x-icon :name="$row['icon']" class="h-4 w-4" /> {{ $row['kind'] }}</span></td>
                            <td class="table-td font-medium text-gray-900">{{ $row['title'] }}</td>
                            <td class="table-td text-gray-600">{{ $row['campus'] }}</td>
                            <td class="table-td whitespace-nowrap text-gray-600">{{ $row['deleted_at']->format('M d, Y g:i A') }}</td>
                            @if ($retentionRunning)
                                @if (in_array($row['record']::class, \App\Support\TrashPurge::MODELS, true))
                                    <x-trash-removal-cell :record="$row['record']" />
                                @else
                                    <td class="table-td text-gray-600">Kept until restored</td>
                                @endif
                            @endif
                            <td class="table-td text-right">
                                <div class="inline-flex items-center gap-4">
                                    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $row['restore_url'] }}">
                                        @csrf
                                        <button type="submit" class="link">Restore</button>
                                    </form>
                                    @if ($row['delete_url'])
                                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $row['delete_url'] }}" data-confirm-form="This permanently deletes {{ $row['title'] }}, with everything attached to it and its files. It cannot be restored afterwards." data-confirm-title="Delete permanently?" data-confirm-button="Delete permanently" data-confirm-tone="danger" data-confirm-focus="cancel">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="link-danger">Delete permanently</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state :colspan="5 + ($retentionRunning ? 1 : 0)" icon="trash" message="Nothing in the archive." hint="Deleted buildings, incidents, drills, advisories, SOS alerts and responders come here, and can be restored from here." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

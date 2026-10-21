@extends('layouts.app')

@section('title', 'After Activity Report - ASCOT DRRMS')
@section('heading', ($record ? 'Edit After Activity Report' : 'After Activity Report') . ' — ' . $drill->drill_type . ' Drill')
@section('subheading', ($drill->campus->campus_name ?? '') . ' · scheduled ' . $drill->scheduled_date->format('M j, Y') . ' · ' . $drill->venue . '. This is separate from the drill’s documentation.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.show", $drill) }}'>Back to drill</x-back-link>
@endsection

{{-- PROVISIONAL field set (agreed with the client) — change together with DrillAfterActivityReportController::rules(). --}}
@php
    $value = fn (string $field, $default = null) => old($field, $record?->{$field} ?? $default);
    $time = fn (string $field, $default = null) => old($field, $record?->{$field}?->format('H:i') ?? $default);
@endphp

@section('content')
    <x-card class="max-w-3xl">
        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.drills.after-activity.update", $drill) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @method('PUT')

            <x-form-section title="Activity">
                <div>
                    <label for="activity_title" class="form-label">Title of activity</label>
                    <input type="text" id="activity_title" name="activity_title" maxlength="200" required
                           value="{{ $value('activity_title', $drill->drill_type . ' Drill') }}" class="form-input">
                    @error('activity_title')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="date_conducted" class="form-label">Date conducted</label>
                        <input type="date" id="date_conducted" name="date_conducted" required max="{{ now('Asia/Manila')->toDateString() }}"
                               value="{{ old('date_conducted', $record?->date_conducted?->toDateString() ?? ($drill->hasStarted() ? $drill->scheduled_date->toDateString() : '')) }}" class="form-input">
                        @error('date_conducted')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="start_time" class="form-label">Start <span class="font-normal text-gray-600">(optional)</span></label>
                        <input type="time" id="start_time" name="start_time" value="{{ $time('start_time', $drill->scheduled_time?->format('H:i')) }}" class="form-input">
                        @error('start_time')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="end_time" class="form-label">End <span class="font-normal text-gray-600">(optional)</span></label>
                        <input type="time" id="end_time" name="end_time" value="{{ $time('end_time') }}" class="form-input">
                        @error('end_time')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="objectives" class="form-label">Objectives <span class="font-normal text-gray-600">(optional)</span></label>
                    <textarea id="objectives" name="objectives" rows="3" maxlength="3000" class="form-input">{{ $value('objectives', $drill->description) }}</textarea>
                    @error('objectives')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="activities_conducted" class="form-label">Activities conducted</label>
                    <textarea id="activities_conducted" name="activities_conducted" rows="5" maxlength="5000" required aria-describedby="activities-help" class="form-input">{{ $value('activities_conducted') }}</textarea>
                    <p id="activities-help" class="form-help">The sequence of the drill: alarm, evacuation, head count, critique.</p>
                    @error('activities_conducted')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="participating_offices" class="form-label">Participating offices and agencies <span class="font-normal text-gray-600">(optional)</span></label>
                    <textarea id="participating_offices" name="participating_offices" rows="2" maxlength="2000" class="form-input">{{ $value('participating_offices', $drill->partner_agency) }}</textarea>
                    @error('participating_offices')<p class="form-error">{{ $message }}</p>@enderror
                </div>
            </x-form-section>

            <x-form-section title="Participants and response" description="Counts only — no names.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    @foreach (['participants_students' => 'Students', 'participants_employees' => 'Faculty and staff', 'participants_others' => 'Others', 'response_time_minutes' => 'Response time (min)'] as $field => $label)
                        <div>
                            <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                            <input type="number" id="{{ $field }}" name="{{ $field }}" min="0" step="{{ $field === 'response_time_minutes' ? '0.1' : '1' }}" inputmode="decimal"
                                   value="{{ $value($field) }}" class="form-input">
                            @error($field)<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <p class="form-help">Response time: from the alarm to the last person reaching the evacuation area, if measured.</p>
            </x-form-section>

            <x-form-section title="Assessment">
                @foreach (['results' => 'Results (against the objectives)', 'problems_encountered' => 'Problems encountered', 'recommendations' => 'Recommendations'] as $field => $label)
                    <div>
                        <label for="{{ $field }}" class="form-label">{{ $label }} <span class="font-normal text-gray-600">(optional)</span></label>
                        <textarea id="{{ $field }}" name="{{ $field }}" rows="3" maxlength="5000" class="form-input">{{ $value($field) }}</textarea>
                        @error($field)<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </x-form-section>

            <x-form-section title="Attachments">
                @include('drill._attachments-field')
            </x-form-section>

            <x-form-actions>
                <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $record ? 'Save After Activity Report' : 'Submit After Activity Report' }}</button>
            </x-form-actions>
        </form>
    </x-card>

    @include('drill._attachments-manage')
@endsection

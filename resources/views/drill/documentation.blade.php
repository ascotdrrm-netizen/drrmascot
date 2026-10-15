@extends('layouts.app')

@section('title', 'Drill Documentation - ASCOT DRRMS')
@section('heading', ($record ? 'Edit documentation' : 'Add documentation') . ' — ' . $drill->drill_type . ' Drill')
@section('subheading', ($drill->campus->campus_name ?? '') . ' · scheduled ' . $drill->scheduled_date->format('M j, Y') . ' · ' . $drill->venue . '. This is separate from the After Activity Report.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.show", $drill) }}'>Back to drill</x-back-link>
@endsection

@section('content')
    <x-card class="max-w-3xl">
        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.drills.documentation.update", $drill) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @method('PUT')

            <x-form-section title="When it was held">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="date_conducted" class="form-label">Date held</label>
                        <input type="date" id="date_conducted" name="date_conducted" required max="{{ now('Asia/Manila')->toDateString() }}"
                               value="{{ old('date_conducted', $record?->date_conducted?->toDateString() ?? ($drill->scheduled_date->isFuture() ? '' : $drill->scheduled_date->toDateString())) }}" class="form-input">
                        @error('date_conducted')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="time_conducted" class="form-label">Start time <span class="font-normal text-gray-600">(optional)</span></label>
                        <input type="time" id="time_conducted" name="time_conducted"
                               value="{{ old('time_conducted', $record?->time_conducted?->format('H:i') ?? $drill->scheduled_time?->format('H:i')) }}" class="form-input">
                        @error('time_conducted')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-form-section>

            <x-form-section title="What happened">
                <div>
                    <label for="narrative" class="form-label">Narrative / observations</label>
                    <textarea id="narrative" name="narrative" rows="6" maxlength="5000" required aria-describedby="narrative-help" class="form-input">{{ old('narrative', $record?->narrative) }}</textarea>
                    <p id="narrative-help" class="form-help">What was done, how people responded, and anything observed.</p>
                    @error('narrative')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="participant_count" class="form-label">Approximate number of participants <span class="font-normal text-gray-600">(optional)</span></label>
                    <input type="number" id="participant_count" name="participant_count" min="0" step="1" inputmode="numeric" aria-describedby="participant_count-help"
                           value="{{ old('participant_count', $record?->participant_count) }}" class="form-input sm:max-w-xs">
                    <p id="participant_count-help" class="form-help">A head count only — no names are recorded.</p>
                    @error('participant_count')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="notes" class="form-label">Notes <span class="font-normal text-gray-600">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000" class="form-input">{{ old('notes', $record?->notes) }}</textarea>
                    @error('notes')<p class="form-error">{{ $message }}</p>@enderror
                </div>
            </x-form-section>

            <x-form-section title="Attachments">
                @include('drill._attachments-field')
            </x-form-section>

            <x-form-actions>
                <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $record ? 'Save documentation' : 'Submit documentation' }}</button>
            </x-form-actions>
        </form>
    </x-card>

    @include('drill._attachments-manage')
@endsection

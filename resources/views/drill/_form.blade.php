@php
    // Schedule form for both roles. Creating — Admin: pick one or more campuses
    // (one drill per campus); Coordinator: always their own campus (enforced in
    // DrillController::store). Editing: the campus is fixed.
    $isEdit = isset($drill);
    $fixedDrillTypes = \App\Models\Drill\Drill::DRILL_TYPES;
    $currentDrillType = $drill->drill_type ?? null;
    $isCustomDrillType = $currentDrillType !== null && ! in_array($currentDrillType, $fixedDrillTypes, true);
    $selectedDrillType = old('drill_type', $isCustomDrillType ? 'Other' : $currentDrillType);
    $drillTypeOtherValue = old('drill_type_other', $isCustomDrillType ? $currentDrillType : '');
    $selectedCampusIds = array_map('strval', (array) old('campus_ids', []));
    $input = 'form-input';
@endphp

<x-card class="max-w-3xl">
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $formAction }}" class="space-y-4" novalidate>
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <x-form-section title="Campus">
        @if ($isEdit || ! $campuses)
            <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                @if ($isEdit)
                    For <span class="font-medium text-gray-900">{{ $drill->campus->campus_name ?? '—' }}</span>.
                    To move it to another campus, delete it and schedule a new one.
                @else
                    For your campus, <span class="font-medium text-gray-900">{{ $ownCampus->campus_name ?? '—' }}</span>.
                @endif
            </div>
        @else
            <fieldset>
                <legend class="form-label is-required">Campuses</legend>
                <p class="caption leading-6">Pick several to schedule the same drill for each — one drill is created per campus, visible to that campus’s coordinators.</p>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ($campuses as $campus)
                        <label class="flex min-h-[2.75rem] cursor-pointer items-center gap-4 rounded-lg border border-gray-200 px-4 text-sm hover:bg-gray-50 has-[:checked]:border-accent-line has-[:checked]:bg-accent-soft">
                            <input type="checkbox" name="campus_ids[]" value="{{ $campus->campus_id }}"
                                   @checked(in_array((string) $campus->campus_id, $selectedCampusIds, true))
                                   class="h-4 w-4 rounded-sm border-gray-300 text-accent focus:ring-accent-line">
                            <span>{{ $campus->campus_name }}</span>
                        </label>
                    @endforeach
                </div>
                @error('campus_ids')<p class="form-error">{{ $message }}</p>@enderror
                @error('campus_ids.*')<p class="form-error">{{ $message }}</p>@enderror
            </fieldset>
        @endif
        </x-form-section>

        <x-form-section title="Drill">
        <div>
            <label for="drill_type" class="form-label">Drill type</label>
            <select id="drill_type" name="drill_type" required
                    onchange="document.getElementById('drill_type_other_wrapper').hidden = this.value !== 'Other'; document.getElementById('drill_type_other').required = this.value === 'Other';"
                    class="{{ $input }}">
                @foreach (array_merge($fixedDrillTypes, ['Other']) as $type)
                    <option value="{{ $type }}" @selected($selectedDrillType === $type)>{{ $type === 'Other' ? 'Other (specify)' : $type }}</option>
                @endforeach
            </select>
            @error('drill_type')<p class="form-error">{{ $message }}</p>@enderror
        </div>

        <div id="drill_type_other_wrapper" @if ($selectedDrillType !== 'Other') hidden @endif>
            <label for="drill_type_other" class="form-label">Specify drill type</label>
            <input type="text" id="drill_type_other" name="drill_type_other" maxlength="100"
                   value="{{ $drillTypeOtherValue }}" @if ($selectedDrillType === 'Other') required @endif
                   class="{{ $input }}">
            @error('drill_type_other')<p class="form-error">{{ $message }}</p>@enderror
        </div>

        </x-form-section>

        <x-form-section title="When and where">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="scheduled_date" class="form-label">Scheduled date</label>
                <input type="date" id="scheduled_date" name="scheduled_date" required
                       value="{{ old('scheduled_date', $isEdit ? $drill->scheduled_date->toDateString() : '') }}"
                       class="{{ $input }}">
                @error('scheduled_date')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="scheduled_time" class="form-label">Scheduled time</label>
                <input type="time" id="scheduled_time" name="scheduled_time" required
                       value="{{ old('scheduled_time', $isEdit && $drill->scheduled_time ? $drill->scheduled_time->format('H:i') : '') }}"
                       class="{{ $input }}">
                @error('scheduled_time')<p class="form-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="venue" class="form-label">Venue</label>
                <input type="text" id="venue" name="venue" maxlength="150" required
                       value="{{ old('venue', $drill->venue ?? '') }}" placeholder="e.g. Covered Court"
                       class="{{ $input }}">
                @error('venue')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="partner_agency" class="form-label">Partner agency <span class="font-normal text-gray-600">(optional)</span></label>
                <input type="text" id="partner_agency" name="partner_agency" maxlength="100" aria-describedby="partner_agency-help"
                       value="{{ old('partner_agency', $drill->partner_agency ?? '') }}" placeholder="e.g. Baler MDRRMO, BFP"
                       class="{{ $input }}">
                <p id="partner_agency-help" class="form-help">{{ config('glossary.partner_agency') }}</p>
                @error('partner_agency')<p class="form-error">{{ $message }}</p>@enderror
            </div>
        </div>

        </x-form-section>

        <x-form-section title="Objectives" description="Printed on the announcement for students and staff, and offered as the After Activity Report’s objectives.">
        <div>
            <label for="description" class="form-label">Description / objectives <span class="font-normal text-gray-600">(optional)</span></label>
            <textarea id="description" name="description" maxlength="1000" rows="4" placeholder="Scenario and objectives — this appears on the printed announcement"
                      class="{{ $input }}">{{ old('description', $drill->description ?? '') }}</textarea>
            @error('description')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        </x-form-section>

        <x-form-actions>
            <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Update schedule' : 'Schedule drill' }}
        </button>
        </x-form-actions>
    </form>
</x-card>

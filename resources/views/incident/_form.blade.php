@php
    $isEdit = isset($incident);
    $formIdPrefix = ($embedded ?? false) ? 'new-incident-' : '';
    // The configured incident types (two groups); anything else stored is Other's typed text.
    $fixedHazardTypes = \App\Models\Incident\Incident::types();
    $currentHazardType = $incident->hazard_type ?? null;
    $isCustomHazardType = $currentHazardType !== null && ! in_array($currentHazardType, $fixedHazardTypes, true);
    // "Record what happened" on a Disaster Advisory pre-fills these ($prefill) and links the incident ($advisory).
    $prefill = $prefill ?? [];
    $advisory = $advisory ?? null;
    $selectedHazardType = old('hazard_type', $isCustomHazardType ? 'Other' : ($currentHazardType ?? ($prefill['hazard_type'] ?? null)));
    $hazardTypeOtherValue = old('hazard_type_other', $isCustomHazardType ? $currentHazardType : ($prefill['hazard_type_other'] ?? ''));
@endphp

<x-card class="max-w-3xl">
    <form method="POST" data-ajax data-ajax-refresh="{{ ($embedded ?? false) ? '#incident-results' : '#page-content' }}" @if ($embedded ?? false) data-ajax-stay data-ajax-reset @endif action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @unless ($isEdit)<input type="hidden" name="client_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endunless
        @if ($isEdit)
            @method('PUT')
        @endif
        @if ($advisory)
            <input type="hidden" name="advisory_id" value="{{ $advisory->advisory_id }}">
            <p class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-from-advisory>
                Recording what happened after the advisory <span class="font-medium">{{ $advisory->headline }}</span> ({{ $advisory->campus_label }}).
                The incident type{{ $advisory->campus_id && $campuses ? ' and campus are' : ' is' }} filled in from it, because they say which event this was.
                Its measurements are not: a measurement is an observation, so enter what was observed, if you know it.
                Check before saving; the incident will be linked to the advisory.
            </p>
        @endif

        <x-form-section title="Incident details">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="hazard_type" class="form-label">Incident type</label>
                <select id="hazard_type" name="hazard_type" required aria-describedby="hazard_type-help"
                        onchange="document.getElementById('hazard_type_other_wrapper').hidden = this.value !== 'Other'; document.getElementById('hazard_type_other').required = this.value === 'Other';"
                        class="form-input">
                    {{-- Two groups (config/incident_types.php), then Other on its own:
                         "neither list covers this". --}}
                    @foreach (\App\Models\Incident\Incident::typeGroups() as $group => $types)
                        <optgroup label="{{ $group }}">
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected($selectedHazardType === $type)>{{ $type }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                    <option value="Other" @selected($selectedHazardType === 'Other')>Other</option>
                </select>
                <p id="hazard_type-help" class="form-help">{{ config('glossary.incident_hazard_type') }}</p>
            </div>

            <div>
                <label for="date_occurred" class="form-label">Date occurred</label>
                <input type="date" id="date_occurred" name="date_occurred" max="{{ now()->toDateString() }}"
                       value="{{ old('date_occurred', isset($incident) ? $incident->date_occurred->toDateString() : ($prefill['date_occurred'] ?? '')) }}" required
                       class="form-input">
            </div>
        </div>

        <div id="hazard_type_other_wrapper" @if ($selectedHazardType !== 'Other') hidden @endif>
            <label for="hazard_type_other" class="form-label">Specify incident type</label>
            <input type="text" id="hazard_type_other" name="hazard_type_other" maxlength="100"
                   value="{{ $hazardTypeOtherValue }}" @if ($selectedHazardType === 'Other') required @endif
                   class="form-input">
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div @if ($routePrefix !== 'admin') class="md:col-span-2" @endif>
                <label for="{{ $formIdPrefix }}status" class="form-label">Status</label>
                <select id="{{ $formIdPrefix }}status" name="status" required aria-describedby="status-help"
                        class="form-input">
                    @foreach (['Active', 'Under Response', 'Resolved'] as $statusOption)
                        <option value="{{ $statusOption }}" @selected(old('status', $incident->status ?? 'Active') === $statusOption)>{{ $statusOption }}</option>
                    @endforeach
                </select>
                <x-term-help id="status-help" :terms="config('glossary.incident_status')" :intro="config('glossary.incident_status_hint')" />
            </div>

            @if ($routePrefix === 'admin')
                <div>
                    <label for="{{ $formIdPrefix }}campus_id" class="form-label">Campus</label>
                    <select id="{{ $formIdPrefix }}campus_id" name="campus_id" required
                            class="form-input">
                        @foreach ($campuses as $campus)
                            <option value="{{ $campus->campus_id }}" @selected((string) old('campus_id', $incident->campus_id ?? ($prefill['campus_id'] ?? '')) === (string) $campus->campus_id)>
                                {{ $campus->campus_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div>
            <label for="description" class="form-label">Description (optional)</label>
            <textarea id="description" name="description" maxlength="2000" rows="4" placeholder="Brief description of what happened, damage observed, or immediate actions taken."
                      class="form-input">{{ old('description', $incident->description ?? '') }}</textarea>
        </div>
        </x-form-section>

        {{-- How many people (thesis panel, Arts & Sciences). Blank = not known, unlike 0. --}}
        <x-form-section title="People" description="How many people this incident affected. All optional: leave a count blank if it isn't known yet.">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4" data-people-counts>
                @foreach (\App\Models\Incident\Incident::PEOPLE as $column => $label)
                    <div>
                        <label for="{{ $column }}" class="form-label">{{ $label }}</label>
                        <input type="number" id="{{ $column }}" name="{{ $column }}" min="0" max="60000" step="1" inputmode="numeric"
                               value="{{ old($column, $incident->{$column} ?? '') }}" class="form-input">
                        @error($column)<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </x-form-section>

        {{-- Measurements, as OBSERVED (config/hazard_measurements.php, read through
             App\Support\HazardMeasurements): one fieldset per Disaster-related type
             that has fields, only the chosen type's shown. The others are DISABLED as
             well as hidden, so they are never submitted (Flood and Landslide share
             "rainfall_warning"). Campus emergencies and Other record none. All optional;
             never carried over from an advisory. --}}
        @php
            // The type the browser shows: the chosen one, or the select's first option.
            $measurementType = $selectedHazardType ?? ($fixedHazardTypes[0] ?? null);
            $measurementSets = collect($fixedHazardTypes)
                ->mapWithKeys(fn ($type) => [$type => \App\Support\HazardMeasurements::forIncident($type)])
                ->filter();
            $measurementValues = old('measurements', $incident->measurements ?? []);
        @endphp
        <x-form-section title="Measurements" description="As observed at the time. All optional: leave a field blank rather than guess."
                        data-incident-measurements :hidden="! $measurementSets->has($measurementType)">
            @foreach ($measurementSets as $type => $fields)
                {{-- The grid sits on an inner div: a `grid` class on the fieldset itself would override `hidden`. --}}
                <fieldset data-measurements-for="{{ $type }}" @if ($measurementType !== $type) hidden disabled @endif>
                    <legend class="sr-only">{{ $type }} measurements</legend>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach ($fields as $key => $field)
                            @php $id = 'm-' . \Illuminate\Support\Str::slug($type) . '-' . $key; $value = $measurementValues[$key] ?? ''; @endphp
                            <div>
                                <label for="{{ $id }}" class="form-label">
                                    {{ $field['label'] }}@isset($field['unit']) <span class="font-normal text-gray-600">({{ $field['unit'] }})</span>@endisset
                                </label>
                                @if ($field['type'] === 'select')
                                    <select id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input">
                                        <option value="">Not known</option>
                                        @foreach ($field['options'] as $optValue => $optLabel)
                                            <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field['type'] === 'number')
                                    <input type="number" id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input" value="{{ $value }}"
                                           min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="{{ $field['step'] }}">
                                @else
                                    <input type="text" id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input" value="{{ $value }}" maxlength="{{ $field['max'] }}">
                                @endif
                                @error("measurements.{$key}")
                                    <p class="form-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            @error('measurements')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </x-form-section>

        <x-form-section title="Photo and location" description="Optional — helps responders find and assess the site.">

        <div>
            <label for="photo" class="form-label">Photo (optional)</label>
            @if ($isEdit && $incident->photo_path)
                <div class="mb-4">
                    <p class="text-xs leading-6 text-gray-600">Current photo:</p>
                    <img src="{{ $incident->photo_url }}" alt="Incident photo" class="h-24 w-24 rounded-lg object-cover">
                </div>
            @endif
            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"
                   class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink hover:file:bg-accent-soft-hover">
        </div>

        <div>
            <label for="photo_caption" class="form-label">What does the photo show? (optional)</label>
            <input type="text" id="photo_caption" name="photo_caption" maxlength="200" class="form-input"
                   value="{{ old('photo_caption', $incident->photo_caption ?? '') }}" placeholder="e.g. Crack along the second-floor stair landing">
            @error('photo_caption')<p class="form-error">{{ $message }}</p>@enderror
        </div>

        @include('building._location-fields', ['model' => $isEdit ? $incident : null, 'campusSelectId' => $formIdPrefix . 'campus_id'])
        </x-form-section>

        <x-form-actions>
            <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Update incident' : 'Report incident' }}
        </button>
        </x-form-actions>
    </form>
</x-card>

@push('scripts')
    <script>
        // Measurements follow the incident type: only the chosen type's fieldset is
        // shown AND enabled (disabled fields aren't submitted); the section hides
        // when the type records none.
        (function () {
            var select = document.getElementById('hazard_type');
            var section = document.querySelector('[data-incident-measurements]');
            if (! select || ! section) return;
            function sync() {
                var any = false;
                section.querySelectorAll('[data-measurements-for]').forEach(function (set) {
                    var on = set.dataset.measurementsFor === select.value;
                    set.hidden = ! on;
                    set.disabled = ! on;
                    any = any || on;
                });
                section.hidden = ! any;
            }
            select.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush

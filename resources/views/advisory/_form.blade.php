{{--
    Disaster Advisory form. The measurement fields come from
    config/hazard_measurements.php: one fieldset per hazard type, only the
    chosen one shown (the others are disabled, so they aren't submitted —
    and the server rejects another type's fields anyway).
--}}
@php
    $isEdit = isset($advisory);
    $hazardTypes = array_keys($measurements);
    $selectedType = old('hazard_type', $advisory->hazard_type ?? 'Typhoon');
    $selectedSource = old('source', $advisory->source ?? 'PAGASA');
    $values = old('measurements', $advisory->measurements ?? []);
    $now = now()->format('Y-m-d\TH:i');
@endphp

<x-card class="max-w-3xl">
    <p class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-no-feed>
        There is no automatic weather or earthquake feed: type in the details from the bulletin you received. The system does not watch the weather by itself.
    </p>

    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $formAction }}" class="space-y-4" data-advisory-form>
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <x-form-section title="The hazard">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="hazard_type" class="form-label">Hazard type</label>
                    <select id="hazard_type" name="hazard_type" required class="form-input" data-hazard-select>
                        @foreach ($hazardTypes as $type)
                            <option value="{{ $type }}" @selected($selectedType === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="campus_id" class="form-label">Campus</label>
                    @if ($isEdit)
                        <p class="form-input bg-gray-50 text-gray-700">{{ $advisory->campus_label }}</p>
                        <p class="form-help">The campus can’t be changed once issued. Issue a new advisory for another campus.</p>
                    @elseif ($campuses)
                        <select id="campus_id" name="campus_id" required class="form-input">
                            <option value="all" @selected(old('campus_id', $advisory->campus_id ?? 'all') === 'all')>All campuses</option>
                            @foreach ($campuses as $campus)
                                <option value="{{ $campus->campus_id }}" @selected((string) old('campus_id', $advisory->campus_id ?? '') === (string) $campus->campus_id)>{{ $campus->campus_name }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="form-input bg-gray-50 text-gray-700">{{ $ownCampus?->campus_name }}</p>
                        <p class="form-help">Coordinators issue advisories for their own campus.</p>
                    @endif
                </div>
            </div>

            <div id="hazard_type_other_wrapper" @if ($selectedType !== 'Other') hidden @endif>
                <label for="hazard_type_other" class="form-label">Specify hazard type</label>
                <input type="text" id="hazard_type_other" name="hazard_type_other" maxlength="100"
                       value="{{ old('hazard_type_other', $advisory->hazard_type_other ?? '') }}" @if ($selectedType === 'Other') required @endif class="form-input">
            </div>

            <div>
                <label for="headline" class="form-label">Headline</label>
                <input type="text" id="headline" name="headline" maxlength="150" required class="form-input"
                       value="{{ old('headline', $advisory->headline ?? '') }}" placeholder="Typhoon Egay — Signal No. 2 over Aurora">
            </div>
        </x-form-section>

        <x-form-section title="Measurements" description="From the bulletin. Only the fields for the chosen hazard are shown.">
            @foreach ($measurements as $type => $fields)
                {{-- The grid sits on an inner div: a `grid` class on the fieldset itself would override `hidden`. --}}
                <fieldset data-measurements-for="{{ $type }}" @if ($selectedType !== $type) hidden disabled @endif>
                    <legend class="sr-only">{{ $type }} measurements</legend>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($fields as $key => $field)
                        @php $id = 'm-' . \Illuminate\Support\Str::slug($type) . '-' . $key; $value = $values[$key] ?? ''; @endphp
                        <div>
                            <label for="{{ $id }}" class="form-label">
                                {{ $field['label'] }}@isset($field['unit']) <span class="font-normal text-gray-600">({{ $field['unit'] }})</span>@endisset
                                @if (! ($field['required'] ?? false)) <span class="font-normal text-gray-600">— optional</span>@endif
                            </label>
                            @if ($field['type'] === 'select')
                                <select id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input" @if ($field['required'] ?? false) required @endif>
                                    <option value="">Choose…</option>
                                    @foreach ($field['options'] as $optValue => $optLabel)
                                        <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($field['type'] === 'number')
                                <input type="number" id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input" value="{{ $value }}"
                                       min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="{{ $field['step'] }}" @if ($field['required'] ?? false) required @endif>
                            @else
                                <input type="text" id="{{ $id }}" name="measurements[{{ $key }}]" class="form-input" value="{{ $value }}"
                                       maxlength="{{ $field['max'] }}" @if ($field['required'] ?? false) required @endif>
                            @endif
                        </div>
                    @endforeach
                    </div>
                </fieldset>
            @endforeach
        </x-form-section>

        <x-form-section title="Source and timing">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="source" class="form-label">Source</label>
                    <select id="source" name="source" required class="form-input"
                            onchange="document.getElementById('source_other_wrapper').hidden = this.value !== 'Other'; document.getElementById('source_other').required = this.value === 'Other';">
                        @foreach (\App\Http\Controllers\Advisory\DisasterAdvisoryController::SOURCES as $source)
                            <option value="{{ $source }}" @selected($selectedSource === $source)>{{ $source }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulletin_reference" class="form-label">Bulletin reference <span class="font-normal text-gray-600">— optional</span></label>
                    <input type="text" id="bulletin_reference" name="bulletin_reference" maxlength="60" class="form-input"
                           value="{{ old('bulletin_reference', $advisory->bulletin_reference ?? '') }}" placeholder="TCB #12">
                </div>
            </div>
            <div id="source_other_wrapper" @if ($selectedSource !== 'Other') hidden @endif>
                <label for="source_other" class="form-label">Specify source</label>
                <input type="text" id="source_other" name="source_other" maxlength="100" class="form-input"
                       value="{{ old('source_other', $advisory->source_other ?? '') }}" @if ($selectedSource === 'Other') required @endif>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label for="issued_at" class="form-label">Issued</label>
                    <input type="datetime-local" id="issued_at" name="issued_at" required class="form-input"
                           value="{{ old('issued_at', isset($advisory) ? $advisory->issued_at->format('Y-m-d\TH:i') : $now) }}">
                </div>
                <div>
                    <label for="expected_at" class="form-label">Expected at the campus <span class="font-normal text-gray-600">— optional</span></label>
                    <input type="datetime-local" id="expected_at" name="expected_at" class="form-input"
                           value="{{ old('expected_at', isset($advisory) ? $advisory->expected_at?->format('Y-m-d\TH:i') : '') }}">
                    <p class="form-help">Leave empty when it can't be forecast, as with an earthquake.</p>
                </div>
                <div>
                    <label for="valid_until" class="form-label">Valid until</label>
                    <input type="datetime-local" id="valid_until" name="valid_until" required class="form-input"
                           value="{{ old('valid_until', isset($advisory) ? $advisory->valid_until->format('Y-m-d\TH:i') : '') }}">
                </div>
            </div>

            @if ($isEdit)
                <div>
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" required class="form-input">
                        @foreach (\App\Http\Controllers\Advisory\DisasterAdvisoryController::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', $advisory->status) === $status)>{{ \App\Models\Advisory\DisasterAdvisory::STATUS_LABELS[$status] }}</option>
                        @endforeach
                    </select>
                    <p class="form-help">Passed or Cancelled takes it off the dashboard warning; it stays in the list.</p>
                </div>
            @else
                <p class="form-help">Issued as <span class="font-medium">In effect</span>: it shows as a warning on the dashboards until its valid-until time, or until you mark it Passed or Cancelled.</p>
            @endif
        </x-form-section>

        <x-form-section title="For students and staff">
            <div>
                <label for="instructions" class="form-label">Instructions</label>
                <textarea id="instructions" name="instructions" rows="5" maxlength="2000" required class="form-input"
                          placeholder="Classes are suspended. Stay indoors, keep away from windows, and follow your building warden.">{{ old('instructions', $advisory->instructions ?? '') }}</textarea>
                <p class="form-help">Printed on the advisory memo for posting and sharing.</p>
            </div>
        </x-form-section>

        <x-form-actions :cancel="route($routePrefix . '.advisories.index')">
            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save Changes' : 'Issue Advisory' }}</button>
        </x-form-actions>
    </form>
</x-card>

@push('scripts')
    <script>
        // Show only the chosen hazard's measurements; the others are disabled so
        // they aren't submitted (the server rejects them anyway).
        (function () {
            var select = document.querySelector('[data-hazard-select]');
            if (! select) return;
            function sync() {
                document.querySelectorAll('[data-measurements-for]').forEach(function (set) {
                    var on = set.dataset.measurementsFor === select.value;
                    set.hidden = ! on;
                    set.disabled = ! on;
                });
                var other = document.getElementById('hazard_type_other_wrapper');
                other.hidden = select.value !== 'Other';
                document.getElementById('hazard_type_other').required = select.value === 'Other';
            }
            select.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush

{{--
    Campus-filtered School dropdown, used everywhere a school is chosen or
    filtered. Attribution only: the school never affects access.

    Props:
      schools        every school (App\Models\School::forDropdowns())
      campusSource   id of the campus select this one follows (null when the
                     campus is fixed, e.g. a Coordinator)
      campusId       the current / fixed campus id (null = none chosen yet,
                     or "All campuses" in filter mode)
      selected       current school_id ("none" allowed in filter mode)
      mode           "form"   placeholder + that campus's schools; when the
                              campus has exactly one school it is SELECTED
                              (a real selected option, so it posts)
                     "filter" "All schools" + "Not assigned" + schools
                              (grouped by campus when no campus is chosen)
      name, id, label, placeholder, hint, autoSubmit, size ("md" | "sm")

    Changing the campus rebuilds the list and clears the choice (JS below),
    so a Zabali campus never sits next to a Casiguran school. The server
    enforces the same rule (App\Rules\SchoolOnCampus).
--}}
@props([
    'schools',
    'campusSource' => null,
    'campusId' => null,
    'selected' => null,
    'mode' => 'form',
    'name' => 'school_id',
    'id' => null,
    'label' => null,
    'placeholder' => '— Select your school —',
    'hint' => null,
    'autoSubmit' => false,
    'size' => 'md',
])

@php
    $id = $id ?? $name;
    $campusId = filled($campusId) ? (int) $campusId : null;
    $isFilter = $mode === 'filter';
    $pool = $campusId !== null
        ? $schools->where('campus_id', $campusId)->values()
        : ($isFilter ? $schools : collect());
    $selected = filled($selected) ? (string) $selected : null;
    // Single-school campus: preselect it — unless this form was just
    // submitted (old input), where the person's own choice (even "none") wins.
    if (! $isFilter && $selected === null && $pool->count() === 1 && ! session()->hasOldInput()) {
        $selected = (string) $pool->first()->school_id;
    }
    $json = $schools->map(fn ($s) => ['id' => $s->school_id, 'campus' => $s->campus_id, 'name' => $s->school_name, 'campusName' => $s->campus_name])->values();
    $errorKey = $name;
    $hasError = $errors->has($errorKey);
    $classes = $isFilter
        ? 'min-h-[2.75rem] max-w-full rounded-lg border bg-white px-4 text-sm text-gray-900 transition duration-150 hover:border-gray-400 focus:border-accent-line focus:outline-hidden focus:ring-[3px] focus:ring-accent-line/30 motion-reduce:transition-none sm:min-h-[2.5rem]'
        : 'block min-h-[2.75rem] w-full rounded-lg border bg-white px-4 text-sm text-gray-900 transition duration-150 focus:border-accent-line focus:outline-hidden focus:ring-[3px] focus:ring-accent-line/30 motion-reduce:transition-none ' . ($size === 'sm' ? 'sm:min-h-[2rem]' : 'sm:min-h-[2.5rem]');
@endphp

@if ($label && $isFilter)
    {{-- Filters offer "Not assigned": say what it means next to the label. --}}
    <span class="inline-flex items-center gap-2">
        <label for="{{ $id }}" class="text-xs font-semibold uppercase leading-6 tracking-wide text-gray-600">{{ $label }}</label>
        <x-info-tip :term="$label" :text="config('glossary.school') . ' ' . config('glossary.not_assigned')" />
    </span>
@elseif ($label)
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
@endif
<select name="{{ $name }}" id="{{ $id }}"
        data-school-select
        data-school-mode="{{ $mode }}"
        data-placeholder="{{ $placeholder }}"
        data-schools="{{ $json->toJson() }}"
        @if ($campusSource) data-campus-source="{{ $campusSource }}" @endif
        @if ($autoSubmit) onchange="this.form.submit()" @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->merge(['class' => $classes . ' ' . ($hasError ? 'border-red-500' : 'border-gray-300')]) }}>
    @if ($isFilter)
        <option value="" @selected($selected === null)>All schools</option>
        <option value="{{ \App\Models\School::NOT_ASSIGNED }}" @selected($selected === \App\Models\School::NOT_ASSIGNED)>Not assigned</option>
        @if ($campusId === null)
            @foreach ($pool->groupBy('campus_name') as $campusName => $group)
                <optgroup label="{{ $campusName }}">
                    @foreach ($group as $school)
                        <option value="{{ $school->school_id }}" @selected($selected === (string) $school->school_id)>{{ $school->school_name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        @else
            @foreach ($pool as $school)
                <option value="{{ $school->school_id }}" @selected($selected === (string) $school->school_id)>{{ $school->school_name }}</option>
            @endforeach
        @endif
    @else
        <option value="">{{ $campusId !== null ? $placeholder : 'Select a campus first' }}</option>
        @foreach ($pool as $school)
            <option value="{{ $school->school_id }}" @selected($selected === (string) $school->school_id)>{{ $school->school_name }}</option>
        @endforeach
    @endif
</select>
@if ($hint)
    <p class="form-help">{{ $hint }}</p>
@endif
@if (! $isFilter)
    @error($errorKey)
        <p id="{{ $id }}-error" class="form-error">{{ $message }}</p>
    @enderror
@endif

@once
    @push('scripts')
        <script>
            // Campus-filtered school dropdowns: when a campus select changes,
            // every school select that follows it (data-campus-source) is
            // rebuilt for that campus and its choice cleared; a single-school
            // campus gets that school selected (form mode). Capture phase, so
            // this runs BEFORE an inline onchange="this.form.submit()" on the
            // campus select — the submitted school is never a stale one.
            (function () {
                function option(value, text) {
                    var o = document.createElement('option');
                    o.value = value;
                    o.textContent = text;
                    return o;
                }

                function rebuild(select, campusId) {
                    var all = JSON.parse(select.dataset.schools || '[]');
                    var isFilter = select.dataset.schoolMode === 'filter';
                    var list = campusId
                        ? all.filter(function (s) { return String(s.campus) === String(campusId); })
                        : (isFilter ? all : []);

                    select.innerHTML = '';
                    if (isFilter) {
                        select.appendChild(option('', 'All schools'));
                        select.appendChild(option('none', 'Not assigned'));
                    } else {
                        select.appendChild(option('', campusId ? select.dataset.placeholder : 'Select a campus first'));
                    }

                    if (isFilter && ! campusId) {
                        var groups = {};
                        list.forEach(function (s) {
                            if (! groups[s.campusName]) {
                                groups[s.campusName] = document.createElement('optgroup');
                                groups[s.campusName].label = s.campusName;
                                select.appendChild(groups[s.campusName]);
                            }
                            groups[s.campusName].appendChild(option(s.id, s.name));
                        });
                    } else {
                        list.forEach(function (s) { select.appendChild(option(s.id, s.name)); });
                    }

                    select.value = (! isFilter && list.length === 1) ? String(list[0].id) : '';
                }

                document.addEventListener('change', function (e) {
                    var source = e.target;
                    if (! source.id) return;
                    document.querySelectorAll('select[data-school-select][data-campus-source="' + source.id + '"]').forEach(function (select) {
                        rebuild(select, source.value);
                    });
                }, true);
            })();
        </script>
    @endpush
@endonce

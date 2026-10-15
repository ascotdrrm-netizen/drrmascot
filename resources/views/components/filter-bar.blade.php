{{--
    The filter bar every list page uses. A page DECLARES its controls; this
    component owns the layout, the labels, the control order, the summary
    line, the Apply button and the Clear filters link.

        <x-filter-bar :action="route('admin.personnel.index')" :summary="$filterSummary"
                      :applied="$filtersApplied" :controls="[
            ['kind' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'All', ...], 'selected' => $role],
            ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $campusId],
            ['kind' => 'school', 'schools' => $schools, 'campusSource' => 'campus_id', 'campusId' => $campusId, 'selected' => $schoolId],
        ]" />

    Controls, always shown broad to narrow whatever order they're declared in:
      period  the Consolidated Report's picker (period_type + its fields), with
              'values' from App\Support\ListPeriod::controlValues(). On a list
              'allDates' => true puts "All dates" first, as the default. Only
              the chosen type's fields are enabled, so only they are submitted.
              A period control always gets Apply filters (its fields follow
              the type), even alone.
      date    a plain date input (name, label, value), ranked with the period:
              for free From/To dates like the Activity Log's
      campus  "All campuses" + campuses (id campus_id). Pass it ONLY where the
              viewer has one today: this component never adds a Campus control,
              and never widens what anyone can see — the controller decides that.
      school  App\Models\School filter select (x-school-select), following the
              campus select when campusSource is given
      select  anything module-specific: name, label, options [value => text], selected;
              an option whose text is an array, label => [value => text], is
              an <optgroup> (the Incidents type filter's two groups);
              'asPeriod' => true ranks it with the period (the Dashboard's Time range)
    One control submits on change; two or more get an Apply button instead —
    never both on one bar. No controls: the bar is just the summary line.

    THE SUMMARY SAYS WHAT IS APPLIED. $summary ['what' => ..., 'who' => ...]
    is built server-side from the filters the query actually used, never from
    what is selected in the controls: someone who changes a dropdown and
    doesn't press Apply still reads the truth about the list on screen. Don't
    "improve" it into reading the form. Rendered as
    "Showing <what> for <who>." or, with a 'period' clause (ListPeriod::phrase()),
    "Showing <what> for <who>, <period>."

    $applied: whether any applied filter differs from its default; then a
    Clear filters link back to $clearHref (default: $action, no query).

    UNKNOWN VALUES ARE IGNORED, deliberately: a filter value the controller
    doesn't recognise (?role=Superuser, ?campus_id=999) is dropped and the
    list shows as unfiltered, so the summary never names a filter that isn't
    applied. That never widens access — who sees what is decided by the
    controllers and App\Support\RecordAccess, not by the filter. THE EXCEPTION
    is school_id: it is validated and REJECTED (App\Rules\SchoolOnCampus),
    because a school from another campus is a cross-campus leak vector;
    SchoolAttributionTest pins that. The other exception is the Dashboard,
    which has always validated range and an Admin's campus_id and rejects a
    bad value with an error (DashboardAnalyticsTest pins it); a coordinator's
    campus_id there is ignored, never rejected. A period type the lists know
    (ListPeriod) with bad fields is also rejected, as on the report.

    The "compared with …" clause belongs to the Dashboard and the report:
    lists don't compare periods.

    <x-slot:lead> comes first in the controls row, in the period's place: the
    Drills month navigator (links, not fields — Drills has no period picker).
    $hidden [name => value] travels with the form (e.g. the drills month and
    view, so changing the campus keeps them).

    <x-slot:aside> holds a page's view switch (e.g. grid/table), at the right
    of the controls — or of the summary on a bar with no controls. It is not a
    filter and is never submitted.

    Every control has the same minimum width on wider screens; the school
    select grows past it when its longest name needs the room. On a phone each
    control is full width.
--}}
@props([
    'action' => null,
    'summary',
    'controls' => [],
    'applied' => false,
    'clearHref' => null,
    'hidden' => [],
])

@php
    // Broad to narrow: period, then campus, then school, then module-specific (as declared).
    $rank = ['period' => 0, 'date' => 0, 'campus' => 1, 'school' => 2];
    // A select that IS the page's period (the Dashboard's Time range) says 'asPeriod' => true.
    $controls = collect($controls)->values()
        ->sortBy(fn (array $control, int $i) => (($control['asPeriod'] ?? false) ? 0 : ($rank[$control['kind']] ?? 3)) * 1000 + $i)
        ->values();
    $autoSubmit = $controls->count() === 1 && $controls->first()['kind'] !== 'period';
    $fieldClass = 'filter-field w-full sm:w-auto';
    $selectClass = 'form-select-inline w-full sm:w-auto sm:min-w-[13rem]';
    $summaryLine = 'Showing <span class="font-medium text-gray-900">' . e($summary['what']) . '</span>'
        . ' for <span class="font-medium text-gray-900">' . e($summary['who']) . '</span>'
        . (isset($summary['period']) ? ', <span class="font-medium text-gray-900">' . e($summary['period']) . '</span>' : '')
        . (isset($summary['compare']) ? ' &mdash; compared with <span class="font-medium text-gray-900">' . e($summary['compare']) . '</span>' : '') . '.';
    // Validation errors for the bar's own fields (they come back to this page).
    $errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $barErrors = collect(['period_type', 'week_date', 'month', 'quarter', 'year', 'start_date', 'end_date', 'campus_id', 'school_id'])
        ->flatMap(fn ($key) => $errorBag->get($key))->unique()->values();
@endphp

<div class="filter-bar" data-filter-bar>
    @if ($controls->isEmpty() && ! isset($lead))
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-600" data-filter-summary>{!! $summaryLine !!}</p>
            @isset($aside)
                <div class="shrink-0" data-filter-aside>{{ $aside }}</div>
            @endisset
        </div>
    @else
        {{-- A form only when there is something to submit; a lead (e.g. the drills
             month navigator) is links, not fields. --}}
        @php $tag = $controls->isEmpty() ? 'div' : 'form'; @endphp
        <{{ $tag }} @if ($tag === 'form') method="GET" action="{{ $action }}" data-filter-form="{{ $autoSubmit ? 'auto' : 'apply' }}" @endif>
            @if ($tag === 'form')
                @foreach ($hidden as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
            @endif
            <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
            <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end">
                @isset($lead)
                    <div class="w-full sm:w-auto" data-filter-lead>{{ $lead }}</div>
                @endisset
                @foreach ($controls as $control)
                    @switch($control['kind'])
                        @case('period')
                            @php
                                $v = $control['values'];
                                $types = (($control['allDates'] ?? false) ? ['' => 'All dates'] : [])
                                    + ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'custom' => 'Custom'];
                                $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                $off = fn (string $type) => (string) $v['period_type'] !== $type;
                            @endphp
                            <div class="flex w-full flex-col gap-4 sm:w-auto sm:flex-row sm:flex-wrap sm:items-end" data-filter-control="period">
                                <div class="{{ $fieldClass }}">
                                    <label for="period_type" class="filter-label">Period</label>
                                    <select name="period_type" id="period_type" class="{{ $selectClass }}" data-filter-period-select>
                                        @foreach ($types as $value => $text)
                                            <option value="{{ $value }}" @selected((string) $v['period_type'] === (string) $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                {{-- One group per type. Only the chosen type's fields are enabled, so
                                     only they are submitted (three "year" inputs share a name). The
                                     `hidden` sits on the outer div: a `flex` class would override it. --}}
                                <div data-filter-period-fields="weekly" @if ($off('weekly')) hidden @endif>
                                    <div class="{{ $fieldClass }}">
                                        <label for="week_date" class="filter-label">Any date in the week</label>
                                        <input type="date" name="week_date" id="week_date" value="{{ $v['week_date'] }}" class="{{ $selectClass }}" @disabled($off('weekly'))>
                                    </div>
                                </div>
                                <div data-filter-period-fields="monthly" @if ($off('monthly')) hidden @endif>
                                    <div class="flex gap-4">
                                        <div class="filter-field min-w-0 flex-1 sm:flex-none">
                                            <label for="month" class="filter-label">Month</label>
                                            <select name="month" id="month" class="{{ $selectClass }}" @disabled($off('monthly'))>
                                                @foreach ($months as $i => $name)
                                                    <option value="{{ $i + 1 }}" @selected((int) $v['month'] === $i + 1)>{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="filter-field w-28">
                                            <label for="month_year" class="filter-label">Year</label>
                                            <input type="number" name="year" id="month_year" value="{{ $v['year'] }}" min="2000" max="2100" class="form-select-inline w-full" @disabled($off('monthly'))>
                                        </div>
                                    </div>
                                </div>
                                <div data-filter-period-fields="quarterly" @if ($off('quarterly')) hidden @endif>
                                    <div class="flex gap-4">
                                        <div class="filter-field min-w-0 flex-1 sm:flex-none">
                                            <label for="quarter" class="filter-label">Quarter</label>
                                            <select name="quarter" id="quarter" class="{{ $selectClass }}" @disabled($off('quarterly'))>
                                                @foreach ([1 => 'Q1 (Jan–Mar)', 2 => 'Q2 (Apr–Jun)', 3 => 'Q3 (Jul–Sep)', 4 => 'Q4 (Oct–Dec)'] as $q => $name)
                                                    <option value="{{ $q }}" @selected((int) $v['quarter'] === $q)>{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="filter-field w-28">
                                            <label for="quarter_year" class="filter-label">Year</label>
                                            <input type="number" name="year" id="quarter_year" value="{{ $v['year'] }}" min="2000" max="2100" class="form-select-inline w-full" @disabled($off('quarterly'))>
                                        </div>
                                    </div>
                                </div>
                                <div data-filter-period-fields="yearly" @if ($off('yearly')) hidden @endif>
                                    <div class="filter-field w-28">
                                        <label for="yearly_year" class="filter-label">Year</label>
                                        <input type="number" name="year" id="yearly_year" value="{{ $v['year'] }}" min="2000" max="2100" class="form-select-inline w-full" @disabled($off('yearly'))>
                                    </div>
                                </div>
                                <div data-filter-period-fields="custom" @if ($off('custom')) hidden @endif>
                                    <div class="flex flex-col gap-4 sm:flex-row">
                                        <div class="{{ $fieldClass }}">
                                            <label for="start_date" class="filter-label">Start date</label>
                                            <input type="date" name="start_date" id="start_date" value="{{ $v['start_date'] }}" class="{{ $selectClass }}" @disabled($off('custom'))>
                                        </div>
                                        <div class="{{ $fieldClass }}">
                                            <label for="end_date" class="filter-label">End date</label>
                                            <input type="date" name="end_date" id="end_date" value="{{ $v['end_date'] }}" min="{{ $v['start_date'] }}" class="{{ $selectClass }}" @disabled($off('custom'))>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @break

                        @case('date')
                            <div class="{{ $fieldClass }}" data-filter-control="{{ $control['name'] }}">
                                <label for="{{ $control['name'] }}" class="filter-label">{{ $control['label'] }}</label>
                                <input type="date" name="{{ $control['name'] }}" id="{{ $control['name'] }}" value="{{ $control['value'] ?? '' }}"
                                       class="{{ $selectClass }}" @if ($autoSubmit) onchange="this.form.submit()" @endif>
                            </div>
                            @break

                        @case('campus')
                            <div class="{{ $fieldClass }}" data-filter-control="campus">
                                <label for="campus_id" class="filter-label">Campus</label>
                                <select name="campus_id" id="campus_id" class="{{ $selectClass }}" @if ($autoSubmit) onchange="this.form.submit()" @endif>
                                    <option value="">All campuses</option>
                                    @foreach ($control['campuses'] as $campus)
                                        <option value="{{ $campus->campus_id }}" @selected((string) ($control['selected'] ?? '') === (string) $campus->campus_id)>{{ $campus->campus_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @break

                        @case('school')
                            <div class="{{ $fieldClass }}" data-filter-control="school">
                                <x-school-select mode="filter" :schools="$control['schools']" :campus-source="$control['campusSource'] ?? null"
                                                 :campus-id="$control['campusId'] ?? null" :selected="$control['selected'] ?? null"
                                                 label="School" :auto-submit="$autoSubmit" class="w-full sm:w-auto sm:min-w-[13rem]" />
                            </div>
                            @break

                        @default
                            <div class="{{ $fieldClass }}" data-filter-control="{{ $control['name'] }}">
                                <label for="{{ $control['name'] }}" class="filter-label">{{ $control['label'] }}</label>
                                <select name="{{ $control['name'] }}" id="{{ $control['name'] }}" class="{{ $selectClass }}" @if ($autoSubmit) onchange="this.form.submit()" @endif>
                                    @foreach ($control['options'] as $value => $text)
                                        @if (is_array($text))
                                            {{-- label => [value => text]: an <optgroup>. --}}
                                            <optgroup label="{{ $value }}">
                                                @foreach ($text as $groupValue => $groupText)
                                                    <option value="{{ $groupValue }}" @selected((string) ($control['selected'] ?? '') === (string) $groupValue)>{{ $groupText }}</option>
                                                @endforeach
                                            </optgroup>
                                        @else
                                            <option value="{{ $value }}" @selected((string) ($control['selected'] ?? '') === (string) $value)>{{ $text }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                    @endswitch
                @endforeach
            </div>
            @isset($aside)
                <div class="shrink-0" data-filter-aside>{{ $aside }}</div>
            @endisset
            </div>

            @foreach ($barErrors as $message)
                <p class="form-error" data-filter-error>{{ $message }}</p>
            @endforeach

            <div class="mt-4 flex flex-col gap-4 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-600" data-filter-summary>{!! $summaryLine !!}</p>
                @php $showApply = $tag === 'form' && ! $autoSubmit; @endphp
                @if ($applied || $showApply)
                    {{-- On a phone: Apply full width on top, Clear filters under it. --}}
                    <div class="flex shrink-0 flex-col-reverse gap-2 sm:flex-row sm:items-center sm:gap-4">
                        @if ($applied)
                            <a href="{{ $clearHref ?? $action }}" class="link-muted inline-flex min-h-[2.75rem] items-center justify-center whitespace-nowrap text-sm sm:min-h-[2.5rem]" data-filter-clear>Clear filters</a>
                        @endif
                        @if ($showApply)
                            <button type="submit" class="btn btn-primary w-full sm:w-auto" data-filter-apply>Apply filters</button>
                        @endif
                    </div>
                @endif
            </div>
        </{{ $tag }}>
    @endif
</div>

@once
    @push('scripts')
        <script>
            // Filter bar period control: show the chosen type's fields and enable
            // only them (disabled fields aren't submitted). It touches nothing
            // else — in particular never the summary line, which states what is
            // applied, not what is selected.
            (function () {
                document.addEventListener('change', function (e) {
                    var select = e.target;
                    if (! select.matches || ! select.matches('[data-filter-period-select]')) return;
                    select.form.querySelectorAll('[data-filter-period-fields]').forEach(function (group) {
                        var on = group.dataset.filterPeriodFields === select.value;
                        group.hidden = ! on;
                        group.querySelectorAll('input, select').forEach(function (field) { field.disabled = ! on; });
                    });
                });
                document.addEventListener('change', function (e) {
                    if (e.target.id !== 'start_date' || ! e.target.form || ! e.target.form.matches('[data-filter-form]')) return;
                    var end = e.target.form.querySelector('#end_date');
                    if (end) end.min = e.target.value;
                });
            })();
        </script>
    @endpush
@endonce

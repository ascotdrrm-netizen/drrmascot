{{--
    Shared "Generate Report" modal, used by every module's list page
    (Buildings/Areas -> module=hazard, Incidents -> module=incidents,
    Drills -> module=drills, Incidents (second button) -> module=alerts).
    One component, four call sites — no per-module copy-pasting of the
    period-picker markup or its vanilla-JS toggle.

    Props:
      module          'hazard' | 'incidents' | 'drills' | 'alerts'
      moduleLabel      human label used in the modal heading/button
      modules          optional [module => label] map; when it has more than
                        one entry the modal shows a "Report" select instead
                        of rendering a second button/modal (Incidents page:
                        incidents + alerts share one modal)
      campuses         Campus[]|null — null hides the campus select
                        (Coordinator is locked to their own campus server-side
                        regardless, this just avoids offering a choice that
                        would be silently overridden)
      selectedCampusId  int|null, only used when campuses is given
      selectedSchoolId  the list page's current school filter (null / "none" /
                        id), preselected here so it carries into the download.
                        The School select follows the Campus select (Admin) or
                        lists the Coordinator's own campus's schools; it is
                        validated server-side by the report Form Requests.
      withSchool       false hides the School select (Drill Tracker: drills
                        are campus-wide and have no school)
    Slot (optional): extra filter fields placed inside the form, above the
    campus select — submitted with the PDF download.
--}}
@props(['module' => null, 'moduleLabel' => null, 'modules' => null, 'campuses' => null, 'selectedCampusId' => null, 'selectedSchoolId' => null, 'withSchool' => true])

@php
    $modules = $modules ?: [$module => $moduleLabel];
    $firstModule = array_key_first($modules);
    $uid = 'report-modal-' . $firstModule;
    $heading = count($modules) > 1 ? 'Generate Period Report' : 'Generate ' . $modules[$firstModule] . ' Report';
    $narrowFields = array_merge(['period'], $campuses ? ['campus'] : [], $withSchool ? ['school'] : []);
    $narrowBy = count($narrowFields) > 1
        ? implode(', ', array_slice($narrowFields, 0, -1)) . ' and ' . end($narrowFields)
        : 'period';
@endphp

<button type="button" data-modal-open="{{ $uid }}" class="btn btn-secondary">
    Generate Report
</button>

<div id="{{ $uid }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" data-modal-backdrop data-report-modal>
    <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl" data-modal-panel role="dialog" aria-modal="true">
        <div class="mb-4 flex items-center justify-between gap-4">
            <h3 class="section-title">{{ $heading }}</h3>
            <button type="button" data-modal-close class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-50 hover:text-gray-900 sm:h-9 sm:w-9" aria-label="Close">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <form method="GET" class="space-y-4" data-report-form>
            @if (count($modules) > 1)
                <div>
                    <label for="{{ $uid }}-module" class="form-label">Report</label>
                    {{-- No name attribute: the module is part of the URL (formaction), not the query string. --}}
                    <select id="{{ $uid }}-module" data-report-module-select
                            class="form-input">
                        @foreach ($modules as $key => $label)
                            <option value="{{ $key }}"
                                    data-pdf-url="{{ route('reports.module.pdf', $key) }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="{{ $uid }}-period_type" class="form-label">Period type</label>
                <select name="period_type" id="{{ $uid }}-period_type" data-period-type-select
                        class="form-input">
                    @foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'custom' => 'Custom'] as $value => $label)
                        <option value="{{ $value }}" @selected($value === 'quarterly')>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div data-period-fields="weekly" class="hidden">
                <label for="{{ $uid }}-week_date" class="form-label">Any date in the week</label>
                <input type="date" name="week_date" id="{{ $uid }}-week_date"
                       class="form-input">
            </div>

            <div data-period-fields="monthly" class="hidden flex gap-4">
                <div class="flex-1">
                    <label for="{{ $uid }}-month" class="form-label">Month</label>
                    <select name="month" id="{{ $uid }}-month"
                            class="form-input">
                        @foreach (['January','February','March','April','May','June','July','August','September','October','November','December'] as $i => $name)
                            <option value="{{ $i + 1 }}" @selected(($i + 1) === (int) now()->format('n'))>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-28">
                    <label for="{{ $uid }}-month_year" class="form-label">Year</label>
                    <input type="number" name="year" id="{{ $uid }}-month_year" value="{{ now()->year }}" min="2000" max="2100"
                           class="form-input">
                </div>
            </div>

            <div data-period-fields="quarterly" class="flex gap-4">
                <div class="flex-1">
                    <label for="{{ $uid }}-quarter" class="form-label">Quarter</label>
                    <select name="quarter" id="{{ $uid }}-quarter"
                            class="form-input">
                        @php $currentQuarter = (int) ceil(now()->month / 3); @endphp
                        <option value="1" @selected($currentQuarter === 1)>Q1 (Jan–Mar)</option>
                        <option value="2" @selected($currentQuarter === 2)>Q2 (Apr–Jun)</option>
                        <option value="3" @selected($currentQuarter === 3)>Q3 (Jul–Sep)</option>
                        <option value="4" @selected($currentQuarter === 4)>Q4 (Oct–Dec)</option>
                    </select>
                </div>
                <div class="w-28">
                    <label for="{{ $uid }}-quarter_year" class="form-label">Year</label>
                    <input type="number" name="year" id="{{ $uid }}-quarter_year" value="{{ now()->year }}" min="2000" max="2100"
                           class="form-input">
                </div>
            </div>

            <div data-period-fields="yearly" class="hidden">
                <label for="{{ $uid }}-yearly_year" class="form-label">Year</label>
                <input type="number" name="year" id="{{ $uid }}-yearly_year" value="{{ now()->year }}" min="2000" max="2100"
                       class="form-input">
            </div>

            <div data-period-fields="custom" class="hidden flex flex-col gap-4 sm:flex-row">
                <div class="flex-1">
                    <label for="{{ $uid }}-start_date" class="form-label">Start date</label>
                    <input type="date" name="start_date" id="{{ $uid }}-start_date"
                           class="form-input">
                </div>
                <div class="flex-1">
                    <label for="{{ $uid }}-end_date" class="form-label">End date</label>
                    <input type="date" name="end_date" id="{{ $uid }}-end_date"
                           class="form-input">
                </div>
            </div>

            {{-- Module-specific extra filters, if a page passes any. --}}
            {{ $slot }}

            @if ($campuses)
                <div>
                    <label for="{{ $uid }}-campus_id" class="form-label">Campus</label>
                    <select name="campus_id" id="{{ $uid }}-campus_id"
                            class="form-input">
                        <option value="" @selected($selectedCampusId === null)>All Campuses</option>
                        @foreach ($campuses as $campus)
                            <option value="{{ $campus->campus_id }}" @selected($selectedCampusId === $campus->campus_id)>{{ $campus->campus_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($withSchool)
            <div>
                <label for="{{ $uid }}-school_id" class="form-label">School</label>
                <x-school-select mode="filter" :schools="\App\Models\School::forDropdowns()" :id="$uid . '-school_id'"
                                 :campus-source="$campuses ? $uid . '-campus_id' : null"
                                 :campus-id="$campuses ? $selectedCampusId : \Illuminate\Support\Facades\Auth::user()?->campus_id"
                                 :selected="$selectedSchoolId" class="block w-full" />
            </div>
            @endif

            {{-- One output: the PDF on the ASCOT letterhead. The helper names only
                 the fields this modal actually shows. --}}
            <div class="flex flex-col gap-4 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-600">Prints on the ASCOT letterhead. Use the {{ $narrowBy }} above to narrow it.</p>
                <button type="submit" data-format="pdf" formaction="{{ route('reports.module.pdf', $firstModule) }}" class="btn btn-primary shrink-0">
                    <x-icon name="download" class="h-5 w-5 shrink-0" />
                    Download PDF
                </button>
            </div>
        </form>
    </div>
</div>

@once
    @push('scripts')
        <script>
            // Generic "Generate Report" modal open/close + period-field toggle,
            // driven entirely by data-* attributes so every module page's
            // instance of this modal component works without its own JS.
            (function () {
                // Open/close animation comes from the layout's shared
                // [data-modal-backdrop]/[data-modal-panel] keyframes.
                //
                // While open the modal is moved to <body>, like the info tips:
                // the page's entrance animation leaves a transform on an
                // ancestor, which would otherwise make "fixed inset-0" cover
                // only the main area (not the sidebar) and clip to its scroll
                // box. A placeholder keeps its spot so it goes back on close.
                function openReportModal(modal) {
                    if (modal.parentNode !== document.body) {
                        modal.drrmsHome = document.createComment('report-modal');
                        modal.parentNode.insertBefore(modal.drrmsHome, modal);
                        document.body.appendChild(modal);
                    }
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.body.classList.add('overflow-hidden');
                }

                function closeReportModal(modal) {
                    document.body.classList.remove('overflow-hidden');
                    window.drrmsMotion.afterCloseAnimation(modal, function () {
                        modal.classList.add('hidden');
                        modal.classList.remove('flex');
                        if (modal.drrmsHome) {
                            modal.drrmsHome.parentNode.replaceChild(modal, modal.drrmsHome);
                            modal.drrmsHome = null;
                        }
                    });
                }

                document.addEventListener('click', function (e) {
                    var opener = e.target.closest('[data-modal-open]');
                    if (opener) {
                        var modal = document.getElementById(opener.dataset.modalOpen);
                        if (modal) openReportModal(modal);
                        return;
                    }

                    var closer = e.target.closest('[data-modal-close]');
                    if (closer) {
                        var openModal = closer.closest('[data-report-modal]');
                        if (openModal) closeReportModal(openModal);
                        return;
                    }

                    // Click on the dark backdrop itself (not its content) also closes.
                    if (e.target.hasAttribute('data-report-modal')) {
                        closeReportModal(e.target);
                    }
                });

                document.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    document.querySelectorAll('[data-report-modal]:not(.hidden)').forEach(closeReportModal);
                });

                document.querySelectorAll('[data-report-modal]').forEach(function (modal) {
                    var select = modal.querySelector('[data-period-type-select]');
                    if (! select) return;

                    function syncPeriodFields() {
                        var selected = select.value;
                        modal.querySelectorAll('[data-period-fields]').forEach(function (el) {
                            el.classList.toggle('hidden', el.dataset.periodFields !== selected);
                        });
                    }

                    select.addEventListener('change', syncPeriodFields);
                    syncPeriodFields();

                    var moduleSelect = modal.querySelector('[data-report-module-select]');
                    if (moduleSelect) {
                        var syncModuleTarget = function () {
                            var option = moduleSelect.options[moduleSelect.selectedIndex];
                            modal.querySelector('[data-format="pdf"]').setAttribute('formaction', option.dataset.pdfUrl);
                        };
                        moduleSelect.addEventListener('change', syncModuleTarget);
                        syncModuleTarget();
                    }

                    var startDate = modal.querySelector('input[name="start_date"]');
                    var endDate = modal.querySelector('input[name="end_date"]');
                    if (startDate && endDate) {
                        startDate.addEventListener('change', function () {
                            endDate.min = startDate.value;
                        });
                    }
                });
            })();
        </script>
    @endpush
@endonce

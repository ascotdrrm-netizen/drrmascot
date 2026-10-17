@extends('layouts.app')

@section('title', 'Drills - ASCOT DRRMS')
@section('heading', 'Drills')
@section('subheading', $routePrefix === 'admin'
    ? 'Every campus’s drills by date. Open a drill to document it or file its After Activity Report.'
    : 'Your campus’s drills by date — scheduled by the DRRMO or by a coordinator. Open a drill to document it or file its After Activity Report.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

{{--
    Drill Tracker: a month calendar (default) or a simple list, chosen with
    ?view=calendar|list and kept in every link. ?month=YYYY-MM picks the month;
    ?date=YYYY-MM-DD (list) narrows to one day — where a calendar day's
    "+n more" leads. Below the md breakpoint (768px) the 7-column grid gives
    way to an agenda: the same month's drills grouped by date.
    Pills are coloured by drill type (Drill::TYPE_COLOURS), never by status.
--}}
@php
    $isAdmin = $routePrefix === 'admin';
    $link = fn (array $over = []) => route("{$routePrefix}.drills.index", array_filter(array_merge([
        'view' => $view === 'list' ? 'list' : null,
        'campus_id' => $isAdmin ? $selectedCampusId : null,
        'month' => $month->format('Y-m'),
    ], $over), fn ($v) => $v !== null));
    $thisMonth = $today->format('Y-m');
    $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $limit = \App\Http\Controllers\Drill\DrillController::DAY_PILL_LIMIT;
    $monthDrills = $drills->filter(fn ($d) => $d->scheduled_date->format('Y-m') === $month->format('Y-m'));
    $pillText = fn ($d) => trim(($d->scheduled_time?->format('g:i A') ?? '') . ' · ' . $d->venue, ' ·');
@endphp

@section('actions')
    @if ($trashedCount > 0)
        <a href="{{ route("{$routePrefix}.drills.trash") }}" class="btn btn-secondary">View Trash ({{ $trashedCount }})</a>
    @endif
    <x-generate-report-modal :modules="['drills' => 'Drill schedule', 'drill-documentation' => 'Drill documentation (consolidated)', 'after-activity' => 'After Activity Reports (consolidated)']"
                             :campuses="$campuses" :selected-campus-id="$selectedCampusId" :with-school="false" />
    <a href="{{ route("{$routePrefix}.drills.create") }}" class="btn btn-primary">+ Schedule Drill</a>
@endsection

@section('content')
    {{-- The shared filter bar: the month navigator first (the period: there is no
         period picker here, so two date controls can never disagree), then Campus
         for the Admin (submits on change, keeping the month and view), and the
         calendar/list switch at the right. A coordinator has no controls. --}}
    <x-filter-bar :action="route($routePrefix . '.drills.index')" :summary="$filterSummary" :applied="$filtersApplied"
                  :clear-href="$link(['campus_id' => null])"
                  :hidden="array_filter(['view' => $view === 'list' ? 'list' : null, 'month' => $month->format('Y-m')])"
                  :controls="$campuses ? [['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId]] : []">
        <x-slot:lead>
            {{-- "< October 2026 >": the month on screen, between the arrows that move it.
                 The label is plain text (the current state), not a button; fixed width so
                 the arrows don't shift between "May" and "September". "Today" appears
                 beside the group only when you've navigated away from the current month.
                 Plain links: works with JavaScript off. --}}
            <div class="filter-field">
                <span class="filter-label" id="month-nav-label">Month</span>
                {{-- sm:h-10 = the campus select's height: the filter bar aligns fields by
                     their bottom edge, so a shorter row would drop this label below CAMPUS. --}}
                <div class="flex items-center gap-2 sm:h-10">
                    <div class="flex items-center gap-1" role="group" aria-labelledby="month-nav-label" data-month-nav>
                        <a href="{{ $link(['month' => $month->subMonth()->format('Y-m'), 'date' => null]) }}" class="btn btn-secondary px-3" aria-label="Previous month" data-month-prev>
                            <x-icon name="chevron_left" class="h-4 w-4" />
                        </a>
                        <span class="w-36 text-center text-sm font-semibold text-gray-900" data-month-label>{{ $month->format('F Y') }}</span>
                        <a href="{{ $link(['month' => $month->addMonth()->format('Y-m'), 'date' => null]) }}" class="btn btn-secondary px-3" aria-label="Next month" data-month-next>
                            <x-icon name="chevron_right" class="h-4 w-4" />
                        </a>
                    </div>
                    @if ($month->format('Y-m') !== $thisMonth)
                        <a href="{{ $link(['month' => $thisMonth, 'date' => null]) }}" class="btn btn-secondary" data-month-today>Today</a>
                    @endif
                </div>
            </div>
        </x-slot:lead>

        <x-slot:aside>
            <nav class="inline-flex rounded-full border border-gray-200 bg-white p-1" aria-label="View">
                @foreach (['calendar' => 'Calendar', 'list' => 'List'] as $key => $label)
                    <a href="{{ $link(['view' => $key === 'list' ? 'list' : null, 'date' => $key === 'list' && $day ? $day->toDateString() : null]) }}"
                       @if ($view === $key) aria-current="page" @endif data-view-link="{{ $key }}"
                       class="inline-flex min-h-[2.75rem] items-center rounded-full px-4 text-sm font-medium sm:min-h-[2rem] {{ $view === $key ? 'bg-accent text-on-accent' : 'text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
                @endforeach
            </nav>
        </x-slot:aside>
    </x-filter-bar>

    {{-- Month heading, e.g. "OCT 2026". --}}
    <h2 class="mb-4 flex items-baseline gap-4" data-month-heading="{{ $month->format('Y-m') }}">
        <span class="text-3xl font-bold tracking-tight text-gray-900">{{ strtoupper($month->format('M')) }}</span>
        <span class="text-3xl font-light text-gray-600">{{ $month->year }}</span>
        @if ($view === 'list' && $day)
            <span class="text-base font-medium text-gray-700">· {{ $day->format('l, F j') }}</span>
        @endif
    </h2>

    @if ($view === 'calendar')
        {{-- md and up: the month grid. --}}
        <div class="card-flush hidden md:block" data-calendar>
            <div class="grid grid-cols-7 border-b border-gray-100 bg-gray-50" aria-hidden="true">
                @foreach ($weekdays as $weekday)
                    <div class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-600">{{ $weekday }}</div>
                @endforeach
            </div>
            <ol class="grid grid-cols-7">
                @for ($date = $gridStart; $date->lte($gridEnd); $date = $date->addDay())
                    @php
                        $key = $date->toDateString();
                        $dayDrills = $byDate[$key] ?? collect();
                        $inMonth = $date->month === $month->month;
                        $isToday = $date->equalTo($today);
                    @endphp
                    <li class="min-h-[7.5rem] min-w-0 border-b border-r border-gray-100 p-2 {{ $date->dayOfWeek === 6 ? 'border-r-0' : '' }} {{ $inMonth ? 'bg-white' : 'bg-gray-50/70' }}"
                        data-day="{{ $key }}" @if (! $inMonth) data-outside-month @endif @if ($isToday) aria-current="date" data-today @endif
                        aria-label="{{ $date->format('l, F j, Y') }}: {{ $dayDrills->count() === 0 ? 'no drills' : $dayDrills->count() . ' ' . \Illuminate\Support\Str::plural('drill', $dayDrills->count()) }}">
                        <div class="mb-1 flex justify-end">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm {{ $isToday ? 'bg-accent font-bold text-on-accent' : ($inMonth ? 'font-medium text-gray-900' : 'text-gray-400') }}" aria-hidden="true">{{ $date->day }}</span>
                        </div>
                        {{-- Outside the month only the number and background fade; pills keep full contrast. --}}
                        <div class="space-y-1">
                            @foreach ($dayDrills->take($limit) as $drill)
                                <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" data-drill-pill="{{ $drill->typeGroup() }}"
                                   title="{{ $drill->drill_type }} — {{ $pillText($drill) }}{{ $isAdmin ? ' (' . ($drill->campus->campus_name ?? '') . ')' : '' }}"
                                   class="block min-w-0 rounded-md border px-2 py-1 text-xs leading-4 hover:brightness-95 focus:outline-hidden focus-visible:ring-[3px] focus-visible:ring-accent-line {{ $drill->typeColours()['pill'] }}">
                                    <span class="block truncate font-semibold uppercase">{{ $drill->drill_type }}</span>
                                    <span class="block truncate uppercase opacity-90">{{ $pillText($drill) }}</span>
                                </a>
                            @endforeach
                            @if ($dayDrills->count() > $limit)
                                <a href="{{ $link(['view' => 'list', 'date' => $key, 'month' => null]) }}" class="link block truncate px-2 text-xs" data-day-overflow>+{{ $dayDrills->count() - $limit }} more</a>
                            @endif
                        </div>
                    </li>
                @endfor
            </ol>
        </div>

        {{-- Below md: an agenda of this month's drills, grouped by date. --}}
        <div class="md:hidden" data-agenda>
            @forelse ($monthDrills->groupBy(fn ($d) => $d->scheduled_date->toDateString()) as $key => $dayDrills)
                @php $date = \Carbon\CarbonImmutable::parse($key); @endphp
                <section class="mb-6" aria-label="{{ $date->format('l, F j, Y') }}">
                    <h3 class="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-900">
                        @if ($date->equalTo($today))<span class="rounded-full bg-accent px-2 text-xs font-bold leading-5 text-on-accent">Today</span>@endif
                        {{ $date->format('D, M j') }}
                    </h3>
                    <ul class="space-y-2">
                        @foreach ($dayDrills as $drill)
                            <li>
                                <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" data-drill-pill="{{ $drill->typeGroup() }}"
                                   class="flex min-h-[2.75rem] min-w-0 flex-col justify-center rounded-lg border px-4 py-2 text-sm {{ $drill->typeColours()['pill'] }}">
                                    <span class="truncate font-semibold uppercase">{{ $drill->drill_type }}</span>
                                    <span class="truncate text-xs uppercase">{{ $pillText($drill) }}{{ $isAdmin ? ' · ' . ($drill->campus->campus_name ?? '') : '' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <x-card>
                    <x-empty-state icon="calendar" message="No drills in {{ $month->format('F Y') }}."
                                   hint="Use + Schedule Drill at the top to add one, or move to another month." />
                </x-card>
            @endforelse
        </div>

        {{-- Legend: drill-type colours. --}}
        <ul class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-700" data-legend aria-label="Drill types">
            @foreach (\App\Models\Drill\Drill::TYPE_COLOURS as $type => $colours)
                <li class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full {{ $colours['dot'] }}" aria-hidden="true"></span>{{ $type }}</li>
            @endforeach
        </ul>
    @else
        {{-- List view: the month (or one day) as rows. --}}
        @if ($day)
            <p class="mb-4 text-sm"><a href="{{ $link(['date' => null]) }}" class="link">Show all of {{ $month->format('F Y') }}</a></p>
        @endif
        <x-card flush>
            <div class="table-wrap">
                <table class="data-table" data-drill-list>
                    <thead>
                        <tr>
                            <th class="table-th">Date</th>
                            <th class="table-th">Drill type</th>
                            <th class="table-th">Venue</th>
                            @if ($isAdmin)<th class="table-th">Campus</th>@endif
                            <th class="table-th">Documentation</th>
                            <th class="table-th">After Activity Report</th>
                            <th class="table-th actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($drills as $drill)
                            <tr class="hover:bg-gray-50">
                                <td class="table-td whitespace-nowrap text-gray-600">
                                    {{ $drill->scheduled_date->format('M d, Y') }}
                                    <span class="block text-xs text-gray-600">{{ $drill->scheduled_time?->format('g:i A') }}</span>
                                </td>
                                <td class="table-td">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $drill->typeColours()['dot'] }}" aria-hidden="true"></span>
                                        <a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="font-medium text-gray-900 hover:text-accent-ink">{{ $drill->drill_type }}</a>
                                    </span>
                                </td>
                                <td class="table-td text-gray-600">{{ $drill->venue }}</td>
                                @if ($isAdmin)<td class="table-td text-gray-600">{{ $drill->campus->campus_name ?? '—' }}</td>@endif
                                <td class="table-td text-gray-600">{{ $drill->documentation_exists ? 'Submitted' : 'Not yet' }}</td>
                                <td class="table-td text-gray-600">{{ $drill->after_activity_report_exists ? 'Submitted' : 'Not yet' }}</td>
                                <td class="table-td"><div class="table-actions"><a href="{{ route("{$routePrefix}.drills.show", $drill) }}" class="link">View</a></div></td>
                            </tr>
                        @empty
                            <x-empty-state :colspan="$isAdmin ? 7 : 6" icon="calendar"
                                           :message="$day ? 'No drills on ' . $day->format('F j, Y') . '.' : 'No drills in ' . $month->format('F Y') . '.'"
                                           hint="Use + Schedule Drill at the top to add one, or move to another month." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
@endsection

@extends('layouts.app')

@section('title', $drill->drill_type . ' Drill - ASCOT DRRMS')
@section('heading', $drill->drill_type . ' Drill — ' . ($drill->campus->campus_name ?? 'Unknown campus'))
@section('subheading', $drill->scheduled_date->format('l, F j, Y') . ($drill->scheduled_time ? ' · ' . $drill->scheduled_time->format('g:i A') : '') . ' · ' . $drill->venue)

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.index", ["month" => $drill->scheduled_date->format("Y-m")]) }}'>Back to drills</x-back-link>
@endsection

{{--
    A drill: its schedule, then its two after-the-drill submissions side by
    side — documentation and the After Activity Report. They are separate
    records with separate files and separate PDFs; neither card shows the
    other's content.
--}}
@php
    $doc = $drill->documentation;
    $aar = $drill->afterActivityReport;
@endphp

@section('actions')
    <a href="{{ route("{$routePrefix}.drills.memorandum", $drill) }}" class="btn btn-secondary">Print announcement</a>
    @if ($canManage)
        <a href="{{ route("{$routePrefix}.drills.edit", $drill) }}" class="btn btn-secondary">Edit schedule</a>
        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.drills.destroy", $drill) }}"
              data-confirm-form="This moves the drill, with its documentation, After Activity Report and their files, to Trash. You can restore it from there."
              data-confirm-title="Move to Trash?" data-confirm-button="Move to Trash" data-confirm-tone="danger" data-confirm-focus="cancel">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    @endif
@endsection

@section('content')
    {{-- Where this drill is in its flow, and the one thing to do next (a jump to that step's card,
         which keeps the only link to its form). --}}
    @php
        // Held once its start time has passed, not as soon as its date comes.
        $notHeldYet = ! $drill->hasStarted();
        $flow = [
            ['label' => 'Scheduled', 'done' => true, 'action' => null, 'href' => null],
            ['label' => 'Documentation', 'done' => (bool) $doc, 'action' => 'Next: documentation', 'href' => '#documentation'],
            ['label' => 'After Activity Report', 'done' => (bool) $aar, 'action' => 'Next: After Activity Report', 'href' => '#after-activity'],
        ];
        $nextStep = collect($flow)->first(fn ($step) => ! $step['done']);
    @endphp
    <nav class="page-section" aria-label="Drill progress" data-drill-progress>
        <ol class="grid grid-cols-3 gap-2">
            @foreach ($flow as $i => $step)
                @php $isNext = $nextStep && $step['label'] === $nextStep['label']; @endphp
                <li @class(['rounded-lg border p-3 text-sm', 'border-green-200 bg-green-50 text-green-800' => $step['done'],
                    'border-accent-line bg-accent-soft text-accent-ink' => $isNext, 'border-gray-200 text-gray-600' => ! $step['done'] && ! $isNext])
                    @if ($isNext) aria-current="step" @endif>
                    <span class="flex items-center gap-2 font-semibold">
                        @if ($step['done'])<x-icon name="check_circle" class="h-4 w-4 shrink-0" />@else<span class="text-xs">{{ $i + 1 }}</span>@endif
                        <span class="min-w-0 break-words">{{ $step['label'] }}</span>
                    </span>
                    <span class="mt-1 block text-xs">{{ $step['done'] ? 'Done' : ($isNext ? 'Next' : 'Waiting') }}</span>
                </li>
            @endforeach
        </ol>
        <p class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700">
            @if (! $nextStep)
                Complete: both reports are in and can be printed below.
            @elseif ($nextStep['label'] === 'Documentation' && $notHeldYet)
                After the drill is held on {{ $drill->scheduled_date->format('M j') }}, add its documentation here.
            @else
                <a href="{{ $nextStep['href'] }}" class="btn btn-secondary btn-sm">{{ $nextStep['action'] }} <x-icon name="keyboard_arrow_down" class="h-4 w-4" /></a>
            @endif
        </p>
    </nav>

    <x-card class="page-section">
        <div class="card-header">
            <h2 class="card-title inline-flex items-center gap-2">
                <span class="h-3 w-3 rounded-full {{ $drill->typeColours()['dot'] }}" aria-hidden="true"></span>
                Schedule
            </h2>
        </div>
        <dl class="detail-list">
            <div><dt class="text-gray-600">Drill type</dt><dd class="text-right font-medium text-gray-900">{{ $drill->drill_type }}</dd></div>
            <div><dt class="text-gray-600">Date and time</dt><dd class="text-right font-medium text-gray-900">{{ $drill->scheduled_date->format('M d, Y') }} · {{ $drill->scheduled_time?->format('g:i A') ?? '—' }}</dd></div>
            <div><dt class="text-gray-600">Venue</dt><dd class="text-right font-medium text-gray-900">{{ $drill->venue }}</dd></div>
            <div><dt class="text-gray-600">Campus</dt><dd class="text-right font-medium text-gray-900">{{ $drill->campus->campus_name ?? '—' }}</dd></div>
            <div><dt class="text-gray-600">Partner agency <x-info-tip term="Partner agency" :text="config('glossary.partner_agency')" /></dt><dd class="text-right font-medium text-gray-900">{{ $drill->partner_agency ?? '—' }}</dd></div>
            <div><dt class="text-gray-600">Scheduled by</dt><dd class="text-right font-medium text-gray-900">{{ $drill->created_by_name ?? '—' }}</dd></div>
        </dl>
        <div class="mt-4 border-t border-gray-100 pt-4">
            <p class="text-sm leading-6 text-gray-600">Description / objectives</p>
            <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $drill->description ?: '—' }}</p>
        </div>
        @if ($otherCampuses->isNotEmpty())
            <div class="mt-4 border-t border-gray-100 pt-4" data-other-campuses>
                <p class="text-sm leading-6 text-gray-600">Same drill at other campuses (each documents its own)</p>
                <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    @foreach ($otherCampuses as $other)
                        <li><a href="{{ route("{$routePrefix}.drills.show", $other) }}" class="link">{{ $other->campus->campus_name ?? 'Unknown campus' }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
        @unless ($canManage)
            @php $byCoordinator = $drill->created_by_name && optional($drill->creator)->role === 'Coordinator'; @endphp
            <p class="caption mt-4">Only {{ $byCoordinator ? $drill->created_by_name . ' (who scheduled it) or the DRRMO' : 'the DRRMO' }} can change or delete this drill’s schedule.</p>
        @endunless
    </x-card>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        {{-- Documentation --}}
        <x-card id="documentation" class="scroll-mt-24" data-section="documentation">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Documentation <x-info-tip term="Documentation" :text="config('glossary.drill_documentation')" /></h2>
                    <p class="caption leading-6">{{ $doc ? 'Submitted by ' . ($doc->submitted_by_name ?? '—') . ' · ' . $doc->created_at?->format('M d, Y') : 'Not submitted yet' }}</p>
                </div>
                @if ($doc)
                    <div class="toolbar">
                        <x-download-pdf :href="route($routePrefix . '.drills.documentation.pdf', $drill)" confirm="Download this drill’s documentation as a PDF?" small />
                        <a href="{{ route("{$routePrefix}.drills.documentation.edit", $drill) }}" class="btn btn-secondary btn-sm">Edit</a>
                    </div>
                @endif
            </div>
            @if ($doc)
                <dl class="detail-list">
                    <div><dt class="text-gray-600">Held on</dt><dd class="text-right font-medium text-gray-900">{{ $doc->date_conducted->format('M d, Y') }}{{ $doc->time_conducted ? ' · ' . $doc->time_conducted->format('g:i A') : '' }}</dd></div>
                    <div><dt class="text-gray-600">Participants (approx.)</dt><dd class="text-right font-medium text-gray-900">{{ $doc->participant_count ?? '—' }}</dd></div>
                </dl>
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-sm leading-6 text-gray-600">Narrative / observations</p>
                    <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $doc->narrative }}</p>
                </div>
                @if ($doc->notes)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="text-sm leading-6 text-gray-600">Notes</p>
                        <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $doc->notes }}</p>
                    </div>
                @endif
                @include('drill._attachment-gallery', ['files' => $documentationFiles])
            @else
                <x-empty-state icon="drills" message="No documentation yet."
                               hint="After the drill, record when it was held, what happened and roughly how many took part, with photos or a PDF."
                               :action-href="route($routePrefix . '.drills.documentation.edit', $drill)" action-label="Add documentation" />
            @endif
        </x-card>

        {{-- After Activity Report --}}
        <x-card id="after-activity" class="scroll-mt-24" data-section="after-activity">
            <div class="card-header">
                <div>
                    <h2 class="card-title">After Activity Report <x-info-tip term="After Activity Report" :text="config('glossary.after_activity_report')" /></h2>
                    <p class="caption leading-6">{{ $aar ? 'Submitted by ' . ($aar->submitted_by_name ?? '—') . ' · ' . $aar->created_at?->format('M d, Y') : 'Not submitted yet' }}</p>
                </div>
                @if ($aar)
                    <div class="toolbar">
                        <x-download-pdf :href="route($routePrefix . '.drills.after-activity.pdf', $drill)" confirm="Download this After Activity Report as a PDF?" small />
                        <a href="{{ route("{$routePrefix}.drills.after-activity.edit", $drill) }}" class="btn btn-secondary btn-sm">Edit</a>
                    </div>
                @endif
            </div>
            @if ($aar)
                <dl class="detail-list">
                    <div><dt class="text-gray-600">Activity</dt><dd class="text-right font-medium text-gray-900">{{ $aar->activity_title }}</dd></div>
                    <div><dt class="text-gray-600">Conducted</dt><dd class="text-right font-medium text-gray-900">{{ $aar->date_conducted->format('M d, Y') }}{{ $aar->start_time ? ' · ' . $aar->start_time->format('g:i A') : '' }}{{ $aar->end_time ? '–' . $aar->end_time->format('g:i A') : '' }}</dd></div>
                    <div><dt class="text-gray-600">Participants</dt><dd class="text-right font-medium text-gray-900">{{ $aar->totalParticipants() ?? '—' }}</dd></div>
                    <div><dt class="text-gray-600">Response time</dt><dd class="text-right font-medium text-gray-900">{{ $aar->response_time_minutes !== null ? rtrim(rtrim(number_format($aar->response_time_minutes, 1), '0'), '.') . ' min' : '—' }}</dd></div>
                </dl>
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-sm leading-6 text-gray-600">Activities conducted</p>
                    <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $aar->activities_conducted }}</p>
                </div>
                @include('drill._attachment-gallery', ['files' => $afterActivityFiles])
            @else
                <x-empty-state icon="check" message="No After Activity Report yet."
                               hint="After the drill, summarise it as an accomplishment: objectives, what was done, who took part, results and recommendations."
                               :action-href="route($routePrefix . '.drills.after-activity.edit', $drill)" action-label="Write After Activity Report" />
            @endif
        </x-card>
    </div>
@endsection

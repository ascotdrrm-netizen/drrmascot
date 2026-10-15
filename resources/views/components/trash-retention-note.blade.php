{{--
    The line at the top of every Trash page: how long things stay, that
    removal is permanent, and whether the purge is actually running. It only
    claims a countdown when `trash:purge` has succeeded within
    config('trash.stale_after_hours') and no backup restore has paused it;
    otherwise it says, in amber, that nothing is being removed (and the pages
    leave out the Removal column). See App\Support\TrashPurge.
--}}
@php
    $days = \App\Support\TrashPurge::retentionDays();
    $window = $days . ' ' . \Illuminate\Support\Str::plural('day', $days);
    $pause = \App\Support\TrashPurge::pause();
    $running = \App\Support\TrashPurge::isRunning();
    $lastRun = \App\Support\TrashPurge::lastSuccess();
@endphp

@if ($pause)
    <p class="page-section rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-trash-retention="paused">
        <span class="font-semibold">{{ \App\Support\TrashPurge::pauseNotice($pause) }}</span> Nothing is being removed from Trash.
        Records restored with it keep the date they were first deleted, so some may already be past the {{ $window }}.
        @if (auth()->user()?->role === 'DRRMO Admin')
            Removal starts again once someone runs <code class="font-mono text-xs">php artisan trash:purge --resume</code>, which first lists what it will delete.
        @endif
    </p>
@elseif ($running)
    <p class="page-section rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700" data-trash-retention="running">
        Anything in Trash is <span class="font-semibold text-gray-900">removed permanently {{ $window }} after it was deleted</span>,
        with its photos and files. This cannot be undone: restore anything you want to keep before then.
        Automatic removal last ran {{ $lastRun->format('M j, Y g:i A') }}.
    </p>
@else
    <p class="page-section rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-trash-retention="stopped">
        <span class="font-semibold">Retention is not currently running</span>
        ({{ $lastRun ? 'it last ran ' . $lastRun->format('M j, Y g:i A') : 'it has never run' }}), so nothing is being removed from Trash.
        Once it runs, anything deleted more than {{ $window }} earlier is removed permanently, with its photos and files.
    </p>
@endif

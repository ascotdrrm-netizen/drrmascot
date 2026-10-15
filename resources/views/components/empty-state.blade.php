{{--
    Friendly empty state: a small icon, one line of text, an optional guidance
    line (what belongs here and how to add it) and an optional action. Use as
    a block, or inside a table via `colspan`, which renders it as a full-width
    <tr> that the layout's row hover skips. When the page header already has
    the add button, the hint names it instead of repeating the link.

    <x-empty-state message="No drills recorded yet." icon="drills"
                   hint="Drills you schedule appear here."
                   :action-href="route('...')" action-label="+ Schedule Drill" />
--}}
@props(['message', 'hint' => null, 'icon' => 'inbox', 'actionHref' => null, 'actionLabel' => null, 'colspan' => null])

@php
    // The page's subject, as a Google Material Symbol (see components/icon).
    $icons = [
        'inbox' => 'inbox', 'buildings' => 'apartment', 'incidents' => 'crisis_alert', 'disasters' => 'campaign',
        'drills' => 'fact_check', 'chart' => 'bar_chart', 'users' => 'group', 'activity' => 'history',
        'trash' => 'delete', 'check' => 'check_circle', 'calendar' => 'event',
    ];
@endphp

@if ($colspan)
    <tr data-empty-row>
        <td colspan="{{ $colspan }}" class="px-4 py-8">
@endif

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-4 py-6 text-center']) }}>
    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-600">
        <x-icon :name="$icons[$icon] ?? 'inbox'" />
    </span>
    <div class="max-w-md">
        <p class="text-sm {{ $hint ? 'font-medium text-gray-900' : 'text-gray-600' }}">{{ $message }}</p>
        @if ($hint)
            <p class="text-sm leading-6 text-gray-600" data-empty-guidance>{{ $hint }}</p>
        @endif
    </div>
    @if ($actionHref && $actionLabel)
        <a href="{{ $actionHref }}" class="btn btn-secondary btn-sm shadow-xs">{{ $actionLabel }}</a>
    @endif
</div>

@if ($colspan)
        </td>
    </tr>
@endif

@props(['value', 'map' => [], 'tip' => null])

@php
    // One colour system for every status/level badge in the app. Known values
    // always use these (so the same word looks the same on every page); a
    // view's own $map only applies to values not listed here. The -800 text on
    // -100/-50 fills with a 1px inset ring keeps contrast well above WCAG AA.
    $canonical = [
        // Danger
        'Active' => 'bg-red-50 text-red-800 ring-red-600/20',
        'High' => 'bg-red-50 text-red-800 ring-red-600/20',
        'Total' => 'bg-red-50 text-red-800 ring-red-600/20',
        'Failed' => 'bg-red-50 text-red-800 ring-red-600/20',
        'In effect' => 'bg-red-50 text-red-800 ring-red-600/20', // an advisory's stored "Active"
        // Warning
        'Under Response' => 'bg-amber-50 text-amber-800 ring-amber-600/25',
        'Medium' => 'bg-amber-50 text-amber-800 ring-amber-600/25',
        'Major' => 'bg-amber-50 text-amber-800 ring-amber-600/25',
        'Partial' => 'bg-amber-50 text-amber-800 ring-amber-600/25',
        'Expired' => 'bg-amber-50 text-amber-800 ring-amber-600/25', // an advisory past its valid-until time
        // OK
        'Resolved' => 'bg-green-50 text-green-800 ring-green-600/20',
        'Low' => 'bg-green-50 text-green-800 ring-green-600/20',
        'Minor' => 'bg-green-50 text-green-800 ring-green-600/20',
        'Sent' => 'bg-green-50 text-green-800 ring-green-600/20',
        // Roles
        'DRRMO Admin' => 'bg-green-50 text-green-800 ring-green-600/20',
        'Coordinator' => 'bg-blue-50 text-blue-800 ring-blue-600/20',
    ];

    // A hover hint with the meaning of known statuses (the info tips and the
    // Help page carry the same sentences for touch and keyboard users). Risk
    // levels get none until the risk checklist defines them.
    $tip = $tip ?? (config('glossary.incident_status')[$value] ?? config('glossary.damage_level')[$value]
        ?? config('glossary.delivery_status')[$value] ?? null);

    $classes = $canonical[$value] ?? (isset($map[$value]) ? $map[$value] . ' ring-gray-500/10' : 'bg-gray-50 text-gray-700 ring-gray-500/20');
@endphp

<span @if ($tip) title="{{ $tip }}" @endif {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2 py-1 text-xs leading-none font-semibold ring-1 ring-inset {$classes}"]) }}>
    {{ $value }}
</span>

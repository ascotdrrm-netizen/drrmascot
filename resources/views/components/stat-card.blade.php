@props(['label', 'value', 'subtitle' => null, 'tone' => 'default'])

@php
    $toneClasses = match ($tone) {
        'danger' => 'text-red-600',
        'success' => 'text-green-600',
        default => 'text-gray-900',
    };
@endphp

<div class="card">
    <p class="break-words text-sm font-medium text-gray-600">{{ $label }}</p>
    <p class="stat-value mt-4 break-all {{ $toneClasses }}">{{ $value }}</p>
    @if ($subtitle)
        <p class="form-help">{{ $subtitle }}</p>
    @endif
</div>

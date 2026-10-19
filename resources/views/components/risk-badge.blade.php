@props(['level' => null, 'score' => null])

{{-- A 5×5 risk-matrix level (App\Support\RiskMatrix), with its score when known; empty = not yet assessed. --}}
@if ($level)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }} data-risk-level="{{ $level }}">
        <x-badge :value="$level" />
        @if ($score)<span class="text-xs text-gray-600" title="Likelihood × impact">{{ $score }}/25</span>@endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-xs text-gray-600']) }} data-risk-level="none">Not yet assessed</span>
@endif

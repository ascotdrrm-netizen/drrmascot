{{-- A field value for PDFs, or an explicit "Not specified" instead of blank space. --}}
@props(['value', 'empty' => 'Not specified'])
@if (filled($value))
    {{ $value }}
@else
    <span class="not-specified">{{ $empty }}</span>
@endif

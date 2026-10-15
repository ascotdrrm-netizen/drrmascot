{{-- Status / risk label for PDFs: the word itself is always printed, so it reads in black & white. --}}
@props(['value'])
@if (filled($value))
    <span class="tag tag-{{ \Illuminate\Support\Str::slug($value) }}">{{ $value }}</span>
@else
    <span class="not-specified">Not specified</span>
@endif

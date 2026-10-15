{{--
    A small "i" button that explains a term in one sentence (a toggletip).
    Tap or click opens it; so does hovering with a mouse. Escape, a second tap,
    or tapping anywhere else closes it. The explanation is also announced to
    screen readers when it opens (role="status").

    <x-info-tip term="Risk level" :text="config('glossary.risk_level')" />

    The panel is position: fixed and placed by the script in layouts/app, so a
    table's scrolling wrapper never clips it and it never widens the page.
    No ids: the same tip can repeat on a page (grid and table views) safely.
--}}
@props(['term', 'text'])

<span {{ $attributes->merge(['class' => 'info-tip relative inline-flex align-middle']) }} data-info-tip>
    <button type="button" class="info-tip-btn" aria-expanded="false" aria-label="What does “{{ $term }}” mean?" data-info-tip-button>
        <x-icon name="info" class="h-4 w-4" />
    </button>
    <span class="info-tip-panel hidden" role="status" data-info-tip-panel><span class="font-semibold">{{ $term }}:</span> {{ $text }}</span>
</span>

{{--
    Dark / light theme switch. The theme lives on <html data-theme>, set before
    first paint by the inline script in each layout's <head>; this button flips
    it and remembers the choice (localStorage "drrms-theme"). The handler is in
    resources/js/app.js (data-theme-toggle).
--}}
@props(['label' => false])
<button type="button" data-theme-toggle aria-label="Switch to light theme"
        {{ $attributes->class([$label ? 'nav-item w-full' : 'icon-btn']) }}>
    <x-icon name="light_mode" data-theme-icon="dark" />
    <x-icon name="dark_mode" data-theme-icon="light" />
    @if ($label)
        <span data-theme-label>Light theme</span>
    @endif
</button>

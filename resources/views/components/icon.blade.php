{{--
    A Google Material Symbols (Rounded) icon, inlined as SVG so it works
    offline and takes the text colour (currentColor). Sized by class: 20px by
    default (UI), pass class="h-6 w-6" for navigation.

        <x-icon name="flood" />                     decorative (hidden from screen readers)
        <x-icon name="sos" fill class="h-6 w-6" />  the filled variant
        <x-icon name="call" label="Call" />         meaningful on its own: announced as "Call"

    The SVGs come from resources/icons/material/ — see App\Support\MaterialIcon.
--}}
@props(['name', 'fill' => false, 'label' => null])
@php
    // 20px unless the classes set a size of their own (h-*, w-*, size-*), so
    // class="text-gray-500" colours the icon without losing its size.
    $sized = preg_match('/(^|\s)(?:[\w-]+:)*(h|w|size)-/', (string) $attributes->get('class'));
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" focusable="false"
     @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif
     {{ $attributes->class(['h-5 w-5 shrink-0' => ! $sized]) }}>{!! \App\Support\MaterialIcon::inner($name, (bool) $fill) !!}</svg>

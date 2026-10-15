{{--
    The one card. Default: padded (16px phones / 24px from 640px).
    flush: no padding, content runs edge to edge — for tables, whose first and
    last cells carry the same 16/24px inset so their text lines up with padded cards.
--}}
@props(['flush' => false])

<div {{ $attributes->merge(['class' => $flush ? 'card-flush' : 'card']) }}>
    {{ $slot }}
</div>

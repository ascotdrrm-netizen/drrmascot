{{--
    Helper text under a select whose options need explaining: one line per
    option, "Option — meaning", from config/glossary.php. Give it an id and
    point the select's aria-describedby at it.

    <x-term-help id="status-help" :terms="config('glossary.incident_status')" />
--}}
@props(['terms', 'intro' => null])

<div {{ $attributes->merge(['class' => 'form-help']) }} data-term-help>
    @if ($intro)
        <p>{{ $intro }}</p>
    @endif
    <ul>
        @foreach ($terms as $term => $meaning)
            <li><span class="font-medium text-gray-700">{{ $term }}</span> — {{ $meaning }}</li>
        @endforeach
    </ul>
</div>

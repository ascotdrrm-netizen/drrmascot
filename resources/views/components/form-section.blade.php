{{--
    A titled group of related fields on a longer form. Sections after the
    first get a divider above them, so one form reads as clear chunks.
    Usage: (x-form-section title="Location" description="optional helper") fields (/x-form-section)
--}}
@props(['title', 'description' => null])

<section {{ $attributes->merge(['class' => 'space-y-4 [&:not(:first-of-type)]:border-t [&:not(:first-of-type)]:border-gray-100 [&:not(:first-of-type)]:pt-6']) }}>
    <div>
        <h2 class="card-title">{{ $title }}</h2>
        @if ($description)
            <p class="caption leading-6">{{ $description }}</p>
        @endif
    </div>
    {{ $slot }}
</section>

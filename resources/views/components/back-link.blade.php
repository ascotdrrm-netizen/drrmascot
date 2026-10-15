@props(['href'])

<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'inline-flex min-h-[2.75rem] items-center gap-1 rounded-full border border-gray-300 bg-white px-4 text-sm font-medium text-gray-600 transition hover:bg-gray-50 hover:border-gray-400 hover:text-gray-900 sm:min-h-[2.25rem]']) }}>
    <x-icon name="chevron_left" class="h-4 w-4" />
    {{ $slot }}
</a>

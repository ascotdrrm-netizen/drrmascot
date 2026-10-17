@extends('layouts.mobile')

@section('title', 'Hotlines - ASCOT DRRM')

@php $tel = fn (string $n) => 'tel:' . preg_replace('/[^0-9+]/', '', $n); @endphp

@section('content')
    <h1 class="page-title">Emergency hotlines</h1>
    <p class="text-sm text-gray-600">Tap a number to call. Calls work even without internet.</p>
    <div class="mt-4 space-y-4">
        @foreach ($groups as $group)
            <section class="rounded-xl border border-gray-100 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-base font-semibold">{{ $group['title'] }}</h2>
                    @isset($group['subtitle'])<p class="text-xs text-gray-600">{{ $group['subtitle'] }}</p>@endisset
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($group['entries'] as $entry)
                        <li class="px-4 py-3">
                            <p class="text-sm font-medium">{{ $entry['label'] }}</p>
                            @if (isset($entry['radio']))
                                <p class="text-sm text-gray-600">Radio {{ $entry['radio'] }}</p>
                            @elseif (empty($entry['numbers']))
                                <p class="text-sm italic text-gray-600">To be provided</p>
                            @else
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($entry['numbers'] as $number)
                                        <a href="{{ $tel($number) }}" class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-lg border border-gray-200 px-3 text-sm font-semibold text-accent-ink"><x-icon name="call" class="h-4 w-4" /> {{ $number }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@endsection

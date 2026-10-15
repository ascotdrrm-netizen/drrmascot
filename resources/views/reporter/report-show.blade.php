@extends('layouts.mobile')

@section('title', 'Report #' . $report['id'] . ' - ASCOT DRRM')

@section('content')
    <a href="{{ route('app.reports') }}" class="inline-flex min-h-[2.75rem] items-center gap-1 text-sm font-medium text-gray-600"><x-icon name="arrow_back" class="h-4 w-4" /> Your reports</a>
    <div class="mt-2 flex items-start gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gray-50"><x-icon :name="$report['icon']" class="h-7 w-7 text-gray-700" /></span>
        <div class="min-w-0">
            <h1 class="page-title">{{ $report['category'] }}</h1>
            <p class="text-sm text-gray-600">Report #{{ $report['id'] }} · {{ $report['when']->format('M j, g:i A') }}</p>
        </div>
    </div>

    <dl class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white text-sm">
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Where</dt><dd class="text-right font-medium">{{ $report['place'] }}</dd></div>
        <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">People hurt</dt><dd class="font-medium">{{ $report['injured'] ?: 'None reported' }}</dd></div>
        <div class="p-4"><dt class="text-gray-600">What you said</dt><dd class="mt-1">{{ $report['description'] }}</dd></div>
    </dl>

    @if (! empty($report['photos']))
        <h2 class="mt-6 text-base font-semibold">Photos</h2>
        <div class="mt-3 grid grid-cols-2 gap-3">
            @foreach ($report['photos'] as $photo)
                <figure class="overflow-hidden rounded-xl border border-gray-100 bg-white">
                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener"><img src="{{ $photo['url'] }}" alt="{{ $photo['caption'] ?: 'Report photo' }}" class="aspect-video w-full object-cover"></a>
                    @if ($photo['caption']) <figcaption class="p-2 text-xs text-gray-600">{{ $photo['caption'] }}</figcaption> @endif
                </figure>
            @endforeach
        </div>
    @endif
    @if (! empty($report['proof_url']))
        <a href="{{ $report['proof_url'] }}" class="link mt-4 inline-block" target="_blank" rel="noopener">View supporting file</a>
    @endif

    <h2 class="mt-6 text-base font-semibold">Status</h2>
    @include('reporter._timeline', ['steps' => $report['timeline']])
@endsection

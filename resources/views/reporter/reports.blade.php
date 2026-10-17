@extends('layouts.mobile')

@section('title', 'Your reports - ASCOT DRRM')

@section('content')
    <div class="flex items-center justify-between">
        <h1 class="page-title">Your reports</h1>
        <a href="{{ route('app.report') }}" class="btn btn-primary btn-sm"><x-icon name="add" class="h-4 w-4" /> New</a>
    </div>
    @if (auth()->user()?->role === 'Reporter')
        <section id="pending-reports" class="mt-6" x-data="pendingReports" x-on:drrms:queue-changed.window="refresh()">
            <h2 class="section-title">Saved on this device</h2>
            <p class="mt-1 text-sm text-gray-600">These reports have not been confirmed by the office yet. They retry while the app is open and online. Sign in with the same account to resume after closing the app.</p>
            <p class="mt-2 text-sm text-red-700" x-show="error" x-text="error" role="alert"></p>
            <div class="mt-3 space-y-3">
                <template x-for="item in items" :key="item.payload.client_key">
                    <article class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="font-semibold" x-text="item.payload.place || 'GPS location'"></p>
                        <p class="text-sm" x-text="label(item)"></p>
                        <p class="mt-1 text-sm text-gray-600" x-text="item.error"></p>
                        <div class="mt-3 flex flex-wrap gap-3 text-sm">
                            <a x-show="item.state === 'attention'" :href="@js(route('app.report')) + '?queued=' + encodeURIComponent(item.payload.client_key)" class="link">Review and edit</a>
                            <button type="button" x-show="['pending', 'auth'].includes(item.state)" x-on:click="retry(item)" class="link">Retry now</button>
                            <button type="button" x-show="item.state !== 'sending'" x-on:click="remove(item)" class="link text-red-700">Remove from device</button>
                        </div>
                    </article>
                </template>
                <p x-show="items.length === 0" class="text-sm text-gray-600">No reports waiting on this device.</p>
            </div>
        </section>
    @endif
    <div id="sent-reports" @if (auth()->user()?->role === 'Reporter') data-live-refresh @endif class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
        @foreach ($reports as $report)
            @include('reporter._report-row', ['report' => $report])
        @endforeach
    </div>
@endsection

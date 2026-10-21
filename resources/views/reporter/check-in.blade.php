@extends('layouts.mobile')
@section('title', 'Safety check-in - ASCOT DRRMS')
@section('content')
    @if (! $rollCall)
        <h1 class="page-title">No open roll call</h1>
        <p class="mt-3 text-sm text-gray-600">Your campus has no safety check-in waiting for your response.</p>
        <a href="{{ route('app.sos') }}" class="btn btn-danger-solid mt-6 w-full">Need help now? Press SOS</a>
    @else
        @php $live = auth()->user()?->role === 'Reporter'; @endphp
        <div x-data="safetyCheckIn({ live: @js($live), url: @js($live ? route('api.reporter.roll-calls.respond', $rollCall['id']) : null), existing: @js($savedResponse ?? null) })" data-check-in>
            <p class="eyebrow text-accent-ink">Roll call · {{ $rollCall['campus'] }}</p>
            <h1 class="mt-1 page-title">Are you safe?</h1>
            <p class="mt-2 text-sm text-gray-600">{{ $rollCall['title'] }}, started {{ $rollCall['started']->diffForHumans() }} by the {{ $rollCall['by'] }}.</p>
            @if ($rollCall['instructions'] ?? null)<p class="mt-3 whitespace-pre-line text-sm">{{ $rollCall['instructions'] }}</p>@endif
            @if ($rollCall['closed'] ?? false)
                <p class="mt-6 rounded-xl border border-gray-200 bg-white p-4">This roll call is closed. Your last response: <strong>{{ ($savedResponse['answer'] ?? null) === 'Safe' ? 'Safe' : (($savedResponse['answer'] ?? null) === 'Help' ? 'Need help' : 'No response') }}</strong>.</p>
            @else
                <div class="mt-6 grid gap-4">
                    <button type="button" x-on:click="answer = 'Safe'; saved = false" :aria-pressed="answer === 'Safe'" class="flex min-h-[5rem] items-center justify-center gap-3 rounded-2xl border-2 border-green-600 bg-green-50 text-xl font-bold text-green-800"><x-icon name="check_circle" class="h-8 w-8" /> I'm safe</button>
                    <button type="button" x-on:click="answer = 'Help'; saved = false" :aria-pressed="answer === 'Help'" class="flex min-h-[5rem] items-center justify-center gap-3 rounded-2xl border-2 border-red-600 bg-red-50 text-xl font-bold text-red-800"><x-icon name="sos" class="h-8 w-8" /> I need help</button>
                </div>
                <div x-show="answer && ! saved" x-cloak class="mt-6 space-y-4">
                    <p class="font-semibold" x-text="answer === 'Safe' ? 'Save my answer: Safe' : 'Save my answer: Need help'"></p>
                    <label for="note" class="form-label">Location or note (optional)</label>
                    <textarea id="note" rows="3" maxlength="1000" class="form-input" x-model="note" placeholder="Where you are and what you need."></textarea>
                    <button type="button" x-on:click="locate()" class="btn btn-secondary w-full"><x-icon name="my_location" /> Share my location (optional)</button>
                    <p class="text-sm text-gray-600" x-text="gpsText" aria-live="polite"></p>
                    <button type="button" x-on:click="save()" :disabled="sending" class="btn btn-primary w-full" x-text="sending ? 'Saving…' : 'Send safety check-in'"></button>
                </div>
                <p x-show="error" x-text="error" class="mt-4 text-sm text-red-700" role="alert"></p>
                <div x-show="saved" x-cloak class="mt-6 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status">
                    <p x-text="answer === 'Safe' ? 'Your response is saved: Safe.' : 'Your response is saved: Need help. The DRRM Office can see it.'"></p>
                    @unless ($live)<p>Preview: nothing is saved.</p>@endunless
                    <button type="button" class="link mt-2" x-on:click="saved = false">Change my answer</button>
                </div>
            @endif
            <a href="{{ route('app.sos') }}" class="btn btn-danger-solid mt-6 w-full"><x-icon name="sos" /> It's an emergency: press SOS</a>
        </div>
    @endif
@endsection
@push('scripts')
<script>
document.addEventListener('alpine:init', function () {
    Alpine.data('safetyCheckIn', function (opts) {
        return {
            answer: opts.existing?.answer || null, note: opts.existing?.note || '', saved: !!opts.existing?.answer,
            sending: false, error: '', coords: null, gpsText: 'Location is shared only if you choose to include it.',
            locate: async function () {
                this.gpsText = 'Finding your location…';
                try {
                    var p = await window.drrmsLocate();
                    this.coords = { latitude: p.latitude, longitude: p.longitude, accuracy_m: p.accuracy };
                    this.gpsText = 'Location ready to share.';
                } catch (error) {
                    this.gpsText = error.message + ' Or describe your location in the note.';
                }
            },
            save: async function () {
                if (! this.answer || this.sending) return;
                this.sending = true; this.error = '';
                try {
                    if (opts.live) await window.drrmsAjax.send({ method: 'post', url: opts.url, data: { answer: this.answer, note: this.note, ...(this.coords || {}) } });
                    this.saved = true;
                } catch (error) { this.error = error.response?.data?.message || 'Not saved. Check your connection and try again.'; }
                finally { this.sending = false; }
            },
        };
    });
});
</script>
@endpush

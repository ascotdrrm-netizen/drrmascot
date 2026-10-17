@extends('layouts.mobile')

@section('title', 'Report a hazard - ASCOT DRRM')

@section('content')
    <div x-data="reportWizard({ categories: @js($categories), preset: @js(request('type')), queuedKey: @js(request('queued')), live: @js(auth()->user()?->role === 'Reporter') })" data-report-wizard>
        <p x-show="draftStatus" x-text="draftStatus" class="mb-4 text-xs text-gray-600" role="status"></p>
        <p x-show="error" x-text="error" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert"></p>
        {{-- Stepper. --}}
        <ol class="flex items-center gap-2" aria-label="Steps">
            <template x-for="(label, i) in steps" :key="i">
                <li class="flex flex-1 flex-col gap-1">
                    <span class="h-1 rounded-full" :class="i <= step ? 'bg-accent' : 'bg-gray-200'"></span>
                    <span class="text-xs font-medium" :class="i === step ? 'text-gray-900' : 'text-gray-600'" x-text="label"></span>
                </li>
            </template>
        </ol>

        {{-- 1. What --}}
        <section x-show="step === 0" class="mt-6">
            <h1 class="page-title">What is happening?</h1>
            <div class="mt-4 grid grid-cols-3 gap-3" role="radiogroup" aria-label="Type of hazard">
                @foreach ($categories as $c)
                    <button type="button" role="radio" :aria-checked="type === @js($c['key'])" x-on:click="type = @js($c['key']); next()"
                            class="flex min-h-[5.5rem] flex-col items-center justify-center gap-2 rounded-xl border p-3 text-center text-xs font-medium"
                            :class="type === @js($c['key']) ? 'border-accent-line bg-accent-soft' : 'border-gray-100 bg-white'">
                        <x-icon :name="$c['icon']" class="h-7 w-7 text-gray-700" />
                        {{ $c['label'] }}
                    </button>
                @endforeach
            </div>
        </section>

        {{-- 2. Where --}}
        <section x-show="step === 1" x-cloak class="mt-6">
            <h1 class="page-title">Where is it?</h1>
            <button type="button" x-on:click="locate()" class="btn btn-secondary mt-4 w-full"><x-icon name="my_location" /> <span x-text="coords ? 'Update my location' : 'Use my location'">Use my location</span></button>
            <p class="mt-2 text-sm" :class="coords ? 'text-green-700' : 'text-gray-600'" x-text="gpsText" aria-live="polite">Your phone’s GPS finds the exact spot.</p>
            <label for="place" class="form-label mt-4">Describe the place</label>
            <input id="place" x-model="place" class="form-input" placeholder="e.g. Engineering Building, 2nd floor stairs">
            <p class="form-help">Building, floor or a landmark helps responders find it fast.</p>
            <div class="mt-6 flex gap-3"><button type="button" class="btn btn-secondary flex-1" x-on:click="back()">Back</button><button type="button" class="btn btn-primary flex-1" x-on:click="next()" :disabled="! coords && ! place.trim()">Next</button></div>
        </section>

        {{-- 3. Details --}}
        <section x-show="step === 2" x-cloak class="mt-6">
            <h1 class="page-title">Tell us more</h1>
            <label for="description" class="form-label mt-4">What happened?</label>
            <textarea id="description" x-model="description" rows="3" class="form-input" placeholder="Short and clear: what you see, how bad it is."></textarea>

            {{-- Thesis panel (Arts & Sciences): how many people are hurt. --}}
            <label for="injured" class="form-label mt-4">How many people are hurt?</label>
            <input id="injured" type="number" min="0" max="999" inputmode="numeric" x-model.number="injured" class="form-input" placeholder="0 if no one">

            <p class="form-label mt-4">Photos (up to 3)</p>
            <label class="flex min-h-[3.5rem] cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-white text-sm font-medium text-accent-ink" x-show="photos.length < 3">
                <x-icon name="photo_camera" /> Take or choose a photo
                <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="addPhoto($event)">
            </label>
            <div class="mt-3 space-y-3">
                <template x-for="(p, i) in photos" :key="p.url">
                    <div class="flex gap-3 rounded-xl border border-gray-100 bg-white p-2">
                        <img :src="p.url" alt="" class="h-20 w-20 shrink-0 rounded-lg object-cover">
                        <div class="min-w-0 flex-1">
                            {{-- Thesis panel (Engineering): every photo says what it shows. --}}
                            <label class="text-xs font-medium text-gray-600" :for="'caption-' + i">What does this photo show?</label>
                            <input :id="'caption-' + i" x-model="p.caption" class="form-input form-input-sm mt-1" placeholder="e.g. Crack on the stair landing">
                        </div>
                        <button type="button" class="icon-btn" x-on:click="removePhoto(i)" aria-label="Remove photo"><x-icon name="close" /></button>
                    </div>
                </template>
            </div>

            {{-- Thesis panel (Accountancy): optional proof file. --}}
            <label for="proof" class="form-label mt-4">Proof or other file (optional)</label>
            <input id="proof" type="file" accept="image/*,application/pdf" class="form-input py-2 file:mr-3 file:rounded-md file:border-0 file:bg-accent-soft file:px-3 file:py-1 file:text-accent-ink" x-on:change="proof = $event.target.files[0] || null">
            <p x-show="proof" x-text="proof?.name || 'Saved proof file'" class="mt-1 text-xs text-gray-600"></p>

            <div class="mt-6 flex gap-3"><button type="button" class="btn btn-secondary flex-1" x-on:click="back()">Back</button><button type="button" class="btn btn-primary flex-1" x-on:click="next()" :disabled="! description.trim()">Review</button></div>
        </section>

        {{-- 4. Review --}}
        <section x-show="step === 3 && ! sent" x-cloak class="mt-6">
            <h1 class="page-title">Check and send</h1>
            <dl class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white text-sm">
                <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Type</dt><dd class="font-medium" x-text="categoryLabel"></dd></div>
                <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Where</dt><dd class="text-right font-medium" x-text="place || 'GPS location'"></dd></div>
                <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">People hurt</dt><dd class="font-medium" x-text="injured === null || injured === '' ? 'Not known' : injured"></dd></div>
                <div class="flex justify-between gap-4 p-4"><dt class="text-gray-600">Photos</dt><dd class="font-medium" x-text="photos.length"></dd></div>
                <div class="p-4"><dt class="text-gray-600">What happened</dt><dd class="mt-1" x-text="description"></dd></div>
            </dl>
            <div class="mt-6 flex gap-3"><button type="button" class="btn btn-secondary flex-1" x-on:click="back()">Back</button><button type="button" class="btn btn-primary flex-1" x-on:click="send()" :disabled="sending" x-text="sending ? 'Sending…' : 'Send report'">Send report</button></div>
        </section>

        {{-- Device storage and a confirmed server submission have distinct states. --}}
        <section x-show="sent" x-cloak class="mt-10 text-center" role="status">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-50 text-green-700">
                <x-icon name="check_circle" fill class="h-10 w-10" />
            </span>
            <h1 class="mt-4 page-title" x-text="queued ? 'Saved on this device' : 'Report sent'">Report sent</h1>
            <p class="mt-1 text-sm text-gray-600" x-text="queued ? 'The office has not confirmed receipt yet. Keep the app open when internet returns, or reopen it while signed in to resume.' : 'The DRRM Office will verify it. Follow its status in Your reports.'"></p>
            <a href="{{ route('app.reports') }}" class="btn btn-primary mt-6 w-full">Your reports</a>
        </section>
    </div>
@endsection

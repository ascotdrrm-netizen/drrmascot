@extends('layouts.mobile')

@section('title', 'Welcome - ASCOT DRRMS')
@section('no-tabs', true)

@section('content')
    <div x-data="installApp()" class="space-y-6 pt-4" data-welcome>
        <div class="text-center">
            <img src="{{ asset('images/drrm-logo-256.png') }}" alt="" class="mx-auto h-20 w-20">
            <h1 class="mt-4 page-title">ASCOT DRRMS on your phone</h1>
            <p class="mt-1 text-sm text-gray-600">From the ASCOT Disaster Risk Reduction and Management Office.</p>
        </div>

        <ul class="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
            <li class="flex gap-4 p-4"><x-icon name="sos" class="h-7 w-7 text-red-600" /><span class="text-sm"><span class="block font-semibold">Call for help in one press</span>SOS sends your location to the DRRM Office and campus security.</span></li>
            <li class="flex gap-4 p-4"><x-icon name="photo_camera" class="h-7 w-7 text-gray-700" /><span class="text-sm"><span class="block font-semibold">Report a hazard with a photo</span>A fallen tree, a flood, a damaged building: the office sees where it is.</span></li>
            <li class="flex gap-4 p-4"><x-icon name="notifications" class="h-7 w-7 text-gray-700" /><span class="text-sm"><span class="block font-semibold">Get advisories and suspensions</span>Typhoon signals, class suspensions and roll calls for your campus.</span></li>
        </ul>

        {{-- Install: Android/desktop Chrome offer a prompt; iPhone needs Share → Add to Home Screen. --}}
        <div class="rounded-xl border border-gray-100 bg-white p-4">
            <p class="text-sm font-semibold">Put it on your home screen</p>
            <p class="mt-1 text-sm text-gray-600">It opens like an app and works without signal for hotlines and guides. No Play Store needed.</p>
            <button type="button" class="btn btn-primary mt-3 w-full" x-show="prompt" x-on:click="install()"><x-icon name="mobile_arrow_down" /> Install the app</button>
            <p class="mt-3 text-sm text-gray-700" x-show="installed" x-cloak>Installed. Open it from your home screen.</p>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700" x-show="ios && ! installed" x-cloak>
                <li>Tap the <span class="font-semibold">Share</span> button in Safari.</li>
                <li>Choose <span class="font-semibold">Add to Home Screen</span>.</li>
                <li>Tap <span class="font-semibold">Add</span>.</li>
            </ol>
            <p class="mt-3 text-xs text-gray-600" x-show="! prompt && ! ios && ! installed">Open this page in Chrome to install it.</p>
        </div>

        <div class="grid gap-3">
            <a href="{{ route('app.register') }}" class="btn btn-primary btn-lg">Create an account</a>
            <a href="{{ route('app.login') }}" class="btn btn-secondary btn-lg">I already have one</a>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        var deferredInstall = null;
        window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferredInstall = e; document.dispatchEvent(new Event('drrms:installable')); });
        document.addEventListener('alpine:init', function () {
            Alpine.data('installApp', function () {
                return {
                    prompt: false, installed: window.matchMedia('(display-mode: standalone)').matches,
                    ios: /iphone|ipad|ipod/i.test(navigator.userAgent),
                    init: function () {
                        var self = this;
                        this.prompt = !! deferredInstall;
                        document.addEventListener('drrms:installable', function () { self.prompt = true; });
                        window.addEventListener('appinstalled', function () { self.installed = true; self.prompt = false; });
                    },
                    install: function () {
                        var self = this;
                        if (! deferredInstall) return;
                        deferredInstall.prompt();
                        deferredInstall.userChoice.then(function (choice) { if (choice.outcome === 'accepted') { self.installed = true; } self.prompt = false; deferredInstall = null; });
                    },
                };
            });
        });
    </script>
@endpush

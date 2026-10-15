@extends('layouts.mobile')

@section('title', 'Log in - ASCOT DRRM')
@section('no-tabs', true)

@section('content')
    <form method="POST" action="{{ route('app.login.store') }}" data-ajax class="space-y-5" x-data="{ forgot: false }" data-reporter-login>
        @csrf
        @if (session('status')) <p class="alert alert-info">{{ session('status') }}</p> @endif
        <div class="pt-6 text-center">
            <img src="{{ asset('images/drrm-logo-256.png') }}" alt="" class="mx-auto h-16 w-16">
            <h1 class="mt-4 page-title">Log in</h1>
            <p class="mt-1 text-sm text-gray-600">ASCOT DRRM, for students and staff.</p>
        </div>
        <div><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" autocomplete="email" class="form-input" required></div>
        <x-password-input name="password" label="Password" autocomplete="current-password" required />
        <button type="submit" class="btn btn-primary btn-lg w-full">Log in</button>

        <div class="text-center text-sm">
            <button type="button" class="link" x-on:click="forgot = ! forgot">Forgot your email or password?</button>
        </div>
        <div x-show="forgot" x-cloak class="space-y-3 rounded-xl border border-gray-100 bg-white p-4 text-sm text-gray-700">
            <p><span class="font-semibold">Forgot your password?</span> Request a reset link at your account email.</p>
            <a href="{{ route('password.request') }}" class="btn btn-secondary w-full">Reset password</a>
            <p><span class="font-semibold">Forgot your email too?</span> Contact the DRRM Office using the emergency hotlines below.</p>
        </div>

        <p class="text-center text-sm text-gray-600">New here? <a href="{{ route('app.register') }}" class="link">Create an account</a></p>
        <a href="{{ route('app.hotlines') }}" class="btn btn-secondary w-full"><x-icon name="call" /> Emergency hotlines, no account needed</a>
    </form>
    <details class="mt-5 rounded-xl border border-gray-100 bg-white p-4 text-sm">
        <summary class="cursor-pointer font-semibold">Resend verification email</summary>
        <form method="POST" action="{{ route('app.verification.resend') }}" class="mt-3 space-y-3">@csrf
            <label for="verification_email" class="form-label">Account email</label>
            <input id="verification_email" name="email" type="email" maxlength="100" required autocomplete="email" class="form-input">
            <button type="submit" class="btn btn-secondary w-full">Request a new verification link</button>
        </form>
    </details>
@endsection

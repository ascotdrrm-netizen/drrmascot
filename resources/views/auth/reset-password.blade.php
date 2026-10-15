@extends('layouts.guest')

@section('title', 'Reset Password - ASCOT DRRMS')

@section('content')
    <h1 class="mb-6 text-xl font-bold text-gray-900">Reset your password</h1>

    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">

        <div>
            <label for="email_display" class="form-label">Email</label>
            <input type="email" id="email_display" value="{{ old('email', $email) }}" disabled
                   class="block w-full rounded-lg border border-gray-200 bg-gray-100 px-4 py-2 text-sm text-gray-500">
        </div>

        <div>
            <x-password-input name="password" label="New password" autocomplete="new-password" required autofocus>
                @include('auth._password-strength', ['for' => 'password'])
            </x-password-input>
        </div>

        <div>
            <x-password-input name="password_confirmation" label="Confirm new password" autocomplete="new-password" required />
        </div>

        <button type="submit" class="btn btn-primary w-full">
            Reset password
        </button>
    </form>

    @if ($errors->any())
        <p class="mt-6 text-center text-sm text-gray-500">
            Link expired or already used?
            <a href="{{ route('password.request') }}" class="link">Request a new one</a>
        </p>
    @endif
@endsection

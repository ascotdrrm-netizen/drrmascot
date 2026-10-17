@extends('layouts.guest')

@section('title', 'Login - ASCOT DRRMS')

@section('content')
    <h1 class="mb-6 text-xl font-bold text-gray-900">Log in</h1>

    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ url('/login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="form-label">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                   class="form-input">
        </div>

        <div>
            <x-password-input name="password" label="Password" autocomplete="current-password" required>
                <x-slot:labelAside>
                    <a href="{{ route('password.request') }}" class="link text-sm">Forgot password?</a>
                </x-slot:labelAside>
            </x-password-input>
        </div>

        <label class="flex items-center gap-3 text-sm text-gray-700">
            <input type="checkbox" name="remember" value="1" class="h-5 w-5 shrink-0" @checked(old('remember'))>
            <span>Keep me signed in on this device</span>
        </label>

        <button type="submit" class="btn btn-primary w-full">
            Log in
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        Don't have an account?
        <a href="{{ url('/register') }}" class="link">Register</a>
    </p>
@endsection

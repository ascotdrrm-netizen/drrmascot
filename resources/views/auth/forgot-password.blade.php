@extends('layouts.guest')

@section('title', 'Forgot Password - ASCOT DRRMS')

@section('content')
    <h1 class="mb-2 text-xl font-bold text-gray-900">Forgot your password?</h1>
    <p class="mb-6 text-sm text-gray-500">Enter your email address and we'll send you a link to reset your password.</p>

    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="form-label">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                   class="form-input">
        </div>

        <button type="submit" class="btn btn-primary w-full">
            Send reset link
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        Remembered your password?
        <a href="{{ route('login') }}" class="link">Log in</a>
    </p>
@endsection

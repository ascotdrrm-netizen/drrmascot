@extends('layouts.guest')

@section('title', 'Register - ASCOT DRRMS')

@section('content')
    <h1 class="mb-6 text-xl font-bold text-gray-900">Register</h1>

    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ url('/register') }}" class="space-y-4">
        @csrf

        <x-form-section title="Your name">
        {{-- Stacked on phones; one row from sm up (first & last wide, initial narrow).
             Combined server-side into "First M. Last" (App\Support\PersonName). --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_5.5rem_minmax(0,1fr)]">
            <div class="min-w-0">
                <label for="first_name" class="form-label">First name</label>
                <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}" required autofocus maxlength="100" autocomplete="given-name"
                       class="form-input">
            </div>
            <div class="min-w-0">
                <label for="middle_initial" class="form-label">M.I. <span class="font-normal text-gray-400">(optional)</span></label>
                <input type="text" id="middle_initial" name="middle_initial" value="{{ old('middle_initial') }}" maxlength="2" autocomplete="additional-name"
                       aria-label="Middle initial (optional)" placeholder="e.g. T"
                       class="form-input">
            </div>
            <div class="min-w-0">
                <label for="last_name" class="form-label">Last name</label>
                <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name"
                       class="form-input">
            </div>
        </div>
        </x-form-section>

        <x-form-section title="Campus and school">

        <div>
            <label for="campus_id" class="form-label">Campus</label>
            <select id="campus_id" name="campus_id" required
                    class="form-input">
                <option value="">-- Select campus --</option>
                @foreach ($campuses as $campus)
                    <option value="{{ $campus->campus_id }}" @selected(old('campus_id') == $campus->campus_id)>
                        {{ $campus->campus_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <x-school-select :schools="$schools" campus-source="campus_id" :campus-id="old('campus_id')" :selected="old('school_id')"
                             label="School" required
                             hint="Choose the school you coordinate for." />
        </div>

        <p class="text-xs text-gray-500">
            New accounts are created as <span class="font-medium text-gray-700">Coordinator</span> for the
            selected campus. DRRMO Admin accounts are created separately by an existing administrator.
        </p>
        </x-form-section>

        <x-form-section title="Contact and sign-in">

        <div>
            <label for="email" class="form-label">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="100"
                   class="form-input">
        </div>

        <div>
            <label for="contact_number" class="form-label">Contact number</label>
            <input type="tel" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" maxlength="20" required autocomplete="tel" inputmode="tel" placeholder="09XX XXX XXXX"
                   class="form-input">
        </div>

        <div>
            <x-password-input name="password" label="Password" autocomplete="new-password" required>
                @include('auth._password-strength', ['for' => 'password'])
            </x-password-input>
        </div>

        <div>
            <x-password-input name="password_confirmation" label="Confirm password" autocomplete="new-password" required />
        </div>
        </x-form-section>

        <button type="submit" class="btn btn-primary w-full">
            Register
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        Already have an account?
        <a href="{{ url('/login') }}" class="link">Log in</a>
    </p>
@endsection

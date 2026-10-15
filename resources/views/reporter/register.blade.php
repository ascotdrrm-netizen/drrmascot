@extends('layouts.mobile')

@section('title', 'Sign up - ASCOT DRRM')
@section('no-tabs', true)

@section('content')
    <div x-data="{ sent: @js(session('status') !== null) }">
        <form x-show="! sent" method="POST" action="{{ route('app.register.store') }}" data-ajax x-on:ajax:success="sent = true; window.scrollTo(0, 0)" class="space-y-5" data-reporter-register>
            @csrf
            <div>
                <h1 class="page-title">Create your account</h1>
                <p class="mt-1 text-sm text-gray-600">For ASCOT students and staff. Any email works: it doesn’t have to be an ASCOT account.</p>
            </div>

            <fieldset class="space-y-2">
                <legend class="form-label">I am a</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['Student' => 'Student', 'Staff' => 'Staff / faculty'] as $value => $role)
                        <label class="flex min-h-[2.75rem] cursor-pointer items-center justify-center rounded-lg border border-gray-200 text-sm font-medium has-[:checked]:border-accent-line has-[:checked]:bg-accent-soft">
                            <input type="radio" name="reporter_kind" value="{{ $value }}" class="sr-only" @checked(old('reporter_kind', 'Student') === $value) required> {{ $role }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid grid-cols-2 gap-3">
                <div><label for="first_name" class="form-label">First name</label><input id="first_name" name="first_name" value="{{ old('first_name') }}" class="form-input" required autocomplete="given-name"></div>
                <div><label for="last_name" class="form-label">Last name</label><input id="last_name" name="last_name" value="{{ old('last_name') }}" class="form-input" required autocomplete="family-name"></div>
            </div>
            <div><label for="id_number" class="form-label">Student / employee ID</label><input id="id_number" name="id_number" value="{{ old('id_number') }}" class="form-input" required placeholder="e.g. 2023-01842"></div>

            <div>
                <label for="campus_id" class="form-label">Campus</label>
                <select id="campus_id" name="campus_id" class="form-input" required>
                    <option value="">Choose your campus</option>
                    @foreach ($campuses as $campus)
                        <option value="{{ $campus->campus_id }}">{{ $campus->campus_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-school-select :schools="$schools" campus-source="campus_id" label="School / course" required />
            </div>

            <div>
                <label for="phone" class="form-label">Mobile number</label>
                <input id="phone" name="contact_number" type="tel" inputmode="tel" autocomplete="tel" value="{{ old('contact_number') }}" class="form-input" required placeholder="09XX XXX XXXX">
                <p class="form-help">The DRRM Office may use this number to contact you during an emergency.</p>
            </div>
            <div>
                <label for="email" class="form-label">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" class="form-input" required>
                <p class="form-help">We’ll send a link to confirm it.</p>
            </div>
            <div>
                <label for="recovery_email" class="form-label">Recovery email (optional)</label>
                <input id="recovery_email" name="recovery_email" type="email" value="{{ old('recovery_email') }}" class="form-input">
                <p class="form-help">If you ever forget the email above, we can find your account with this one.</p>
            </div>

            <x-password-input name="password" label="Password" autocomplete="new-password" required />
            <x-password-input name="password_confirmation" label="Confirm password" autocomplete="new-password" required />

            {{-- Data Privacy Act (RA 10173). --}}
            <label class="flex items-start gap-3 rounded-lg border border-gray-100 bg-white p-3 text-sm text-gray-700">
                <input type="checkbox" name="privacy_consent" value="1" required class="mt-1 h-5 w-5 shrink-0">
                <span>I agree that the ASCOT DRRM Office may use my name, number and, only when I press SOS or send a report, my location, to respond to emergencies (Data Privacy Act, RA 10173).</span>
            </label>

            <button type="submit" class="btn btn-primary btn-lg w-full">Create account</button>
            <p class="text-center text-sm text-gray-600">Already have one? <a href="{{ route('app.login') }}" class="link">Log in</a></p>
        </form>

        {{-- What happens next: the panel's three steps. --}}
        <section x-show="sent" x-cloak class="space-y-5" role="status">
            <div class="text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-700"><x-icon name="mail" class="h-8 w-8" /></span>
                <h1 class="mt-4 page-title">Check your email</h1>
                <p class="mt-1 text-sm text-gray-600">Your account is ready once three things happen:</p>
            </div>
            <ol class="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
                <li class="flex gap-3 p-4"><span class="font-display text-xl font-semibold text-accent-ink">1</span><span class="text-sm"><span class="font-semibold">You confirm your email</span> with the link we sent.</span></li>
                <li class="flex gap-3 p-4"><span class="font-display text-xl font-semibold text-accent-ink">2</span><span class="text-sm"><span class="font-semibold">Your campus coordinator</span> confirms you study or work there.</span></li>
                <li class="flex gap-3 p-4"><span class="font-display text-xl font-semibold text-accent-ink">3</span><span class="text-sm"><span class="font-semibold">The DRRMO Admin approves</span> your account. We’ll text you.</span></li>
            </ol>
            <p class="text-center text-xs text-gray-500">In an emergency before then, call the hotlines: they never need an account.</p>
            <a href="{{ route('app.hotlines') }}" class="btn btn-secondary w-full"><x-icon name="call" /> Emergency hotlines</a>
        </section>
    </div>
@endsection

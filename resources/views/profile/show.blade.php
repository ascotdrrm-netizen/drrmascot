@extends('layouts.app')

@section('title', 'My Profile - ASCOT DRRMS')
@section('heading', 'My Profile')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

@section('content')
    <x-phone-verification />
    <section class="my-6 rounded-xl border border-gray-100 bg-white p-4">
        <h2 class="text-base font-semibold">Email verification</h2>
        @if($personnel->hasVerifiedEmail())<p class="mt-2 text-sm text-green-700">Your email is verified for campus broadcasts.</p>
        @else
            <p class="mt-2 text-sm text-gray-600">Verify {{ $personnel->email }} to receive campus broadcast emails.</p>
            <form method="POST" action="{{ route('email.verification.send') }}" class="mt-3">@csrf<button type="submit" class="btn btn-secondary">Request verification link</button></form>
            @error('verification_email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @endif
    </section>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card>
            <h2 class="card-title mb-4">Profile details</h2>

            <div class="mb-6 flex items-center gap-4">
                <div class="group relative h-20 w-20 shrink-0 cursor-pointer" onclick="document.getElementById('photo').click()">
                    {{-- No src without a photo: an empty src re-requests this page. The upload preview sets it. --}}
                    <img id="avatar-preview" @if ($personnel->photo_url) src="{{ $personnel->photo_url }}" @endif alt="{{ $personnel->full_name }}"
                         class="h-20 w-20 rounded-full object-cover {{ $personnel->photo_url ? '' : 'hidden' }}">
                    <div id="avatar-placeholder"
                         class="flex h-20 w-20 items-center justify-center rounded-full bg-green-100 text-2xl font-semibold text-green-700 {{ $personnel->photo_url ? 'hidden' : '' }}">
                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($personnel->full_name, 0, 1)) }}
                    </div>
                    <span class="absolute bottom-0 right-0 flex h-6 w-6 items-center justify-center rounded-full bg-accent text-on-accent ring-2 ring-white transition group-hover:bg-accent-hover">
                        <x-icon name="photo_camera" class="h-4 w-4" />
                    </span>
                    <input type="file" id="photo" name="photo" form="profile-form" accept="image/jpeg,image/png,image/webp"
                           class="hidden" onchange="handleAvatarPhotoChange(this)">
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ $personnel->full_name }}</p>
                    <p class="text-xs text-gray-600">{{ $personnel->role }}</p>
                    <p class="caption leading-6">Click the photo to change it</p>
                </div>
            </div>

            <dl class="detail-list mb-6">
                <div class="flex justify-between">
                    <dt class="text-gray-600">Email</dt>
                    <dd class="font-medium text-gray-900">{{ $personnel->email }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Campus</dt>
                    <dd class="font-medium text-gray-900">{{ $personnel->campus->campus_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-600">School</dt>
                    <dd class="text-right font-medium text-gray-900">{{ $personnel->school->school_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Role</dt>
                    <dd class="font-medium text-gray-900">{{ $personnel->role }}</dd>
                </div>
            </dl>

            <form id="profile-form" method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.profile.update") }}" enctype="multipart/form-data" class="space-y-4 border-t border-gray-100 pt-6">
                @csrf

                <div>
                    <label for="full_name" class="form-label">Full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="150"
                           value="{{ old('full_name', $personnel->full_name) }}" required
                           class="form-input">
                </div>

                <div>
                    <label for="contact_number" class="form-label">Contact number</label>
                    <input type="tel" id="contact_number" name="contact_number" maxlength="13" inputmode="numeric" pattern="(09\d{9}|\+?639\d{9})" data-digits autocomplete="tel" placeholder="09171234567" title="Digits only, like 09171234567"
                           value="{{ old('contact_number', $personnel->contact_number) }}"
                           class="form-input">
                </div>

                <x-form-actions>
                    <button type="submit" class="btn btn-primary">
                    Save changes
                </button>
                </x-form-actions>
            </form>
        </x-card>

        <div class="space-y-6">
        <x-card>
            <h2 class="card-title mb-4">Change password</h2>

            <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.profile.password") }}" class="space-y-4">
                @csrf

                <div>
                    <x-password-input name="current_password" label="Current password" autocomplete="current-password" required />
                </div>

                <div>
                    <x-password-input name="password" label="New password" autocomplete="new-password" required>
                        @include('auth._password-strength', ['for' => 'password'])
                    </x-password-input>
                </div>

                <div>
                    <x-password-input name="password_confirmation" label="Confirm new password" autocomplete="new-password" required />
                </div>

                <x-form-actions>
                    <button type="submit" class="btn btn-primary">
                    Change password
                </button>
                </x-form-actions>
            </form>
        </x-card>
        </div>
    </div>

    @push('scripts')
        <script>
            function handleAvatarPhotoChange(input) {
                const file = input.files && input.files[0];
                if (! file) return;

                const reader = new FileReader();
                reader.onload = function (e) {
                    const preview = document.getElementById('avatar-preview');
                    const placeholder = document.getElementById('avatar-placeholder');
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        </script>
    @endpush
@endsection

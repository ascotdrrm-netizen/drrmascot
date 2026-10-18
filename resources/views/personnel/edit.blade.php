@extends('layouts.app')

@section('title', 'Edit Account - ASCOT DRRMS')
@section('heading', 'Edit Account')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.personnel.index') }}">Back to User Management</x-back-link>
@endsection

@section('content')
    <x-card class="max-w-3xl">
        <dl class="detail-list">
            <div class="flex justify-between">
                <dt class="text-gray-600">Email</dt>
                <dd class="font-medium text-gray-900">{{ $personnel->email }}</dd>
            </div>
        </dl>
        <p class="mb-6 text-xs leading-6 text-gray-600">Email can't be changed here — it's the account's login identifier.</p>

        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.personnel.update', $personnel) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="full_name" class="form-label">Full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="150"
                           value="{{ old('full_name', $personnel->full_name) }}" required
                           class="form-input">
                    @error('full_name')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="contact_number" class="form-label">Contact number</label>
                    <input type="tel" id="contact_number" name="contact_number" maxlength="13" inputmode="numeric" pattern="(09\d{9}|\+?639\d{9})" data-digits placeholder="09171234567" title="Digits only, like 09171234567"
                           value="{{ old('contact_number', $personnel->contact_number) }}"
                           class="form-input">
                    @error('contact_number')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="role" class="form-label">Role</label>
                    <select name="role" id="role" required
                            class="form-input">
                        <option value="Reporter" @selected(old('role', $personnel->role) === 'Reporter')>Reporter (student or staff)</option>
                        <option value="Coordinator" @selected(old('role', $personnel->role) === 'Coordinator')>Coordinator</option>
                        <option value="DRRMO Admin" @selected(old('role', $personnel->role) === 'DRRMO Admin')>DRRMO Admin</option>
                    </select>
                    <p class="form-help">An approved student officer can be assigned as a Coordinator for their campus and school.</p>
                    @error('role')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="campus_id" class="form-label">Campus</label>
                    <select name="campus_id" id="campus_id" required
                            class="form-input">
                        @foreach ($campuses as $campus)
                            <option value="{{ $campus->campus_id }}" @selected((string) old('campus_id', $personnel->campus_id) === (string) $campus->campus_id)>
                                {{ $campus->campus_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('campus_id')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    {{-- Changing the campus clears (or, for a one-school campus, re-selects) the school. --}}
                    <x-school-select :schools="$schools" campus-source="campus_id"
                                     :campus-id="old('campus_id', $personnel->campus_id)"
                                     :selected="session()->hasOldInput() ? old('school_id') : $personnel->school_id"
                                     label="School" placeholder="— No school (e.g. General Services Unit) —"
                                     hint="Attribution only — the school is recorded on what this person creates; it never changes what they can access." />
                </div>
            </div>

            <x-form-actions :cancel="route('admin.personnel.index')">
                <button type="submit" class="btn btn-primary">Save changes</button>
            </x-form-actions>
        </form>
    </x-card>
@endsection

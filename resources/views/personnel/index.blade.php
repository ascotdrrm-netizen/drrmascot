@extends('layouts.app')

@section('title', 'User Management - ASCOT DRRMS')
@section('heading', 'User Management')
@section('subheading', 'Everyone with an account. People sign up themselves on the Register page and can sign in once you approve them; here you can also correct their details, campus, school or role.')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.dashboard') }}">Back to dashboard</x-back-link>
@endsection

@section('actions')
    <x-download-pdf :href="route('admin.personnel.report')" confirm="Download a PDF list of these accounts?" />
@endsection

@section('content')
    @if ($pending->isNotEmpty())
        {{-- Self-registered accounts can't sign in until approved here. --}}
        <x-card class="mb-6 border border-amber-300" data-pending-accounts>
            <h2 class="card-title">Waiting for approval ({{ $pending->count() }})</h2>
            <p class="caption mb-4">
                {{ $pending->count() === 1 ? 'This person' : 'These people' }} registered on the Register page and can't sign in until you approve.
                Rejecting removes the registration.
            </p>
            <ul class="divide-y divide-gray-100">
                @foreach ($pending as $person)
                    <li class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between" data-pending="{{ $person->personnel_id }}">
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-gray-900">{{ $person->full_name }}</p>
                            <p class="truncate text-gray-600">{{ $person->email }}{{ $person->contact_number ? ' · ' . $person->contact_number : '' }}</p>
                            <p class="text-gray-600">{{ $person->campus->campus_name ?? '—' }} · {{ $person->school->school_name ?? 'no school' }}</p>
                            @if ($person->role === 'Reporter')
                                <p class="text-gray-600">Reporter · Email {{ $person->hasVerifiedEmail() ? 'verified' : 'unverified' }} · Campus {{ $person->validated_by_coordinator_at ? 'validated' : 'not validated' }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 gap-4">
                            <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.personnel.reject', $person) }}"
                                  data-confirm-form="Reject {{ $person->full_name }}'s registration? It is removed; they would have to register again."
                                  data-confirm-title="Reject registration?" data-confirm-button="Reject" data-confirm-tone="danger" data-confirm-focus="cancel">
                                @csrf
                                <button type="submit" class="btn btn-danger">Reject</button>
                            </form>
                            @if ($person->role !== 'Reporter' || ($person->hasVerifiedEmail() && $person->validated_by_coordinator_at))
                                <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.personnel.approve', $person) }}"
                                      data-confirm-form="Approve {{ $person->full_name }} for {{ $person->campus->campus_name ?? 'their campus' }}? They can sign in right away.">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">Approve</button>
                                </form>
                            @else
                                <span class="text-sm text-gray-600">Waiting for verification and campus validation</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-filter-bar :action="route('admin.personnel.index')" :summary="$filterSummary" :applied="$filtersApplied" :controls="[
        ['kind' => 'campus', 'campuses' => $campuses, 'selected' => $selectedCampusId],
        ['kind' => 'school', 'schools' => $schools, 'campusSource' => 'campus_id', 'campusId' => $selectedCampusId, 'selected' => $selectedSchoolId],
        ['kind' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'All', 'Coordinator' => 'Coordinator', 'DRRMO Admin' => 'DRRMO Admin', 'Reporter' => 'Reporter'], 'selected' => $selectedRole],
    ]" />

    <x-card flush>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="table-th">Full name</th>
                        <th class="table-th">Email</th>
                        <th class="table-th">Role</th>
                        <th class="table-th">Campus / School <x-info-tip term="School" :text="config('glossary.school') . ' A dash means no school is set.'" /></th>
                        <th class="table-th">Contact number</th>
                        <th class="table-th actions">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($personnel as $person)
                        <tr class="hover:bg-gray-50">
                            <td class="table-td font-medium text-gray-900">{{ $person->full_name }}</td>
                            <td class="table-td text-gray-600">{{ $person->email }}</td>
                            <td class="table-td">
                                <x-badge :value="$person->role" :map="[
                                    'DRRMO Admin' => 'bg-green-100 text-green-700',
                                    'Coordinator' => 'bg-blue-100 text-blue-700',
                                ]" />
                            </td>
                            <td class="table-td text-gray-600">
                                {{ $person->campus->campus_name ?? ($person->role === 'DRRMO Admin' ? 'All campuses' : '—') }}
                                <span class="block text-xs text-gray-600">{{ $person->school->school_name ?? '—' }}</span>
                            </td>
                            <td class="table-td text-gray-600">{{ $person->contact_number ?? '—' }}</td>
                            <td class="table-td">
                                <div class="table-actions">
                                    <a href="{{ route('admin.personnel.edit', $person) }}" class="link">Edit</a>
                                    @if (Auth::id() === $person->personnel_id)
                                        <span class="text-gray-600">You</span>
                                    @else
                                        @php
                                            $confirmMessage = "This permanently deletes {$person->full_name}'s account. It cannot be undone. Incidents and inspections they recorded are kept, but only the DRRMO will be able to see them; drills they scheduled stay, and only the DRRMO can then change them.";
                                        @endphp
                                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.personnel.destroy', $person) }}" data-confirm-form="{{ $confirmMessage }}" data-confirm-title="Delete account?" data-confirm-button="Delete permanently" data-confirm-tone="danger" data-confirm-focus="cancel">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="link-danger">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="users" message="No personnel records found." hint="No account matches these filters. Set them back to All to see everyone." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection

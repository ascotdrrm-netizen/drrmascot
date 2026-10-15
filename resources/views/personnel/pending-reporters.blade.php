@extends('layouts.app')

@section('title', 'Reporter Validation - ASCOT DRRMS')
@section('heading', 'Reporter Validation')
@section('subheading', 'Confirm that each verified applicant studies or works on your campus. The DRRMO Admin gives final approval.')

@section('content')
    <x-card>
        @forelse ($pending as $person)
            <div class="flex flex-col gap-3 border-b border-gray-100 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-semibold">{{ $person->full_name }} <span class="font-normal text-gray-600">· {{ $person->reporter_kind }}</span></p>
                    <p class="text-sm text-gray-600">{{ $person->id_number }} · {{ $person->school?->school_name }} · {{ $person->email }}</p>
                </div>
                <form method="POST" action="{{ route('coordinator.reporters.validate', $person) }}" data-ajax data-ajax-refresh="#page-content"
                      data-confirm-form="Confirm that {{ $person->full_name }} studies or works on this campus?">
                    @csrf
                    <button type="submit" class="btn btn-primary">Validate</button>
                </form>
            </div>
        @empty
            <p class="text-sm text-gray-600">No verified Reporter accounts are waiting for campus validation.</p>
        @endforelse
    </x-card>
@endsection

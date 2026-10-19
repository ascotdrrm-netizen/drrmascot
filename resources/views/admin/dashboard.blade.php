@extends('layouts.app')

@section('title', 'Admin Dashboard - ASCOT DRRMS')
@section('charts', 'yes')
@section('heading', 'Dashboard')
@section('subheading', 'Welcome, ' . Auth::user()->full_name . ' · DRRMO Admin')

{{-- No quick-link buttons here: every module is one click away in the sidebar
     (one tap away in the phone menu), so a second set only repeated it. --}}

@section('content')
    @include('dashboard._advisory-banner')
    @include('dashboard._backup-banner')
    @if ($pendingAccounts > 0)
        <div class="mb-6 flex flex-col gap-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between" data-pending-banner>
            <p>
                <span class="font-semibold">{{ $pendingAccounts }} {{ \Illuminate\Support\Str::plural('account', $pendingAccounts) }} waiting for approval.</span>
                {{ $pendingAccounts === 1 ? 'This person' : 'These people' }} registered and can't sign in until you approve.
            </p>
            <a href="{{ route('admin.personnel.index') }}" class="btn btn-secondary shrink-0">Review in User Management</a>
        </div>
    @endif
    @include('dashboard._analytics')
@endsection

@extends('layouts.app')

@section('title', 'Coordinator Dashboard - ASCOT DRRMS')
@section('charts', 'yes')
@section('heading', 'Dashboard')
@section('subheading', 'Welcome, ' . Auth::user()->full_name . ' · ' . (Auth::user()->campus->campus_name ?? 'No campus'))

{{-- Only the one action the sidebar doesn't already offer: reporting an
     incident. Buildings and drills are in the sidebar. --}}
@section('actions')
    <a href="{{ route('coordinator.incidents.create') }}" class="btn btn-primary">+ Report incident</a>
@endsection

@section('content')
    @include('dashboard._advisory-banner')
    @include('dashboard._analytics')
@endsection

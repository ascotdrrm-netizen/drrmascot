@extends('layouts.app')

@section('title', 'Add Damage Report - ASCOT DRRMS')
@section('heading', 'Add Damage Report')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.incidents.show", $incident) }}'>{{ $incident->hazard_type }} — {{ $incident->date_occurred->format('M d, Y') }}</x-back-link>
@endsection

@section('content')
    @include('incident.damage-reports._form', ['formAction' => route("{$routePrefix}.incidents.damage-reports.store", $incident)])
@endsection

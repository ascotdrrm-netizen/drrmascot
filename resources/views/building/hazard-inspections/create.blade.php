@extends('layouts.app')

@section('title', 'Add Inspection - ASCOT DRRMS')
@section('heading', 'Add Hazard Inspection')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.show", $building) }}'>{{ $building->building_name }}</x-back-link>
@endsection

@section('content')
    @include('building.hazard-inspections._form', ['formAction' => route("{$routePrefix}.buildings.inspections.store", $building)])
@endsection

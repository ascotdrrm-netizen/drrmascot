@extends('layouts.app')

@section('title', 'Edit Inspection - ASCOT DRRMS')
@section('heading', 'Edit Hazard Inspection')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.show", $building) }}'>{{ $building->building_name }}</x-back-link>
@endsection

@section('content')
    @include('building.hazard-inspections._form', ['formAction' => route("{$routePrefix}.buildings.inspections.update", [$building, $inspection])])
@endsection

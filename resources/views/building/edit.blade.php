@extends('layouts.app')

@section('title', 'Edit Building/Area - ASCOT DRRMS')
@section('heading', 'Edit Building/Area')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.show", $building) }}'>Back to Building/Area</x-back-link>
@endsection

@section('content')
    @include('building._form', ['formAction' => route("{$routePrefix}.buildings.update", $building)])
@endsection

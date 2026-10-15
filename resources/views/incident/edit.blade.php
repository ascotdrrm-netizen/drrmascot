@extends('layouts.app')

@section('title', 'Edit Incident - ASCOT DRRMS')
@section('heading', 'Edit Incident')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.incidents.show", $incident) }}'>Back to incident</x-back-link>
@endsection

@section('content')
    @include('incident._form', ['formAction' => route("{$routePrefix}.incidents.update", $incident)])
@endsection

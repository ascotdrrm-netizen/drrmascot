@extends('layouts.app')

@section('title', 'Report Incident - ASCOT DRRMS')
@section('heading', 'Report Incident')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.incidents.index") }}'>Back to incidents</x-back-link>
@endsection

@section('content')
    @include('incident._form', ['formAction' => route("{$routePrefix}.incidents.store")])
@endsection

@extends('layouts.app')

@section('title', 'Schedule Drill - ASCOT DRRMS')
@section('heading', 'Schedule Drill')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.index") }}'>Back to drills</x-back-link>
@endsection

@section('content')
    @include('drill._form', ['formAction' => route("{$routePrefix}.drills.store")])
@endsection

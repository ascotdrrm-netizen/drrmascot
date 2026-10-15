@extends('layouts.app')

@section('title', 'Add Evaluation - ASCOT DRRMS')
@section('heading', 'Add Evaluation')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.show", $drill) }}'>{{ $drill->drill_type }} — {{ $drill->date_conducted->format('M d, Y') }}</x-back-link>
@endsection

@section('content')
    @include('drill.evaluations._form', ['formAction' => route("{$routePrefix}.drills.evaluations.store", $drill)])
@endsection

@extends('layouts.app')

@section('title', 'Issue Disaster Advisory - ASCOT DRRMS')
@section('heading', 'Issue Disaster Advisory')
@section('subheading', 'A warning before or during an event, from the bulletin you received.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.advisories.index") }}'>Back to disaster advisories</x-back-link>
@endsection

@section('content')
    @include('advisory._form', ['formAction' => route("{$routePrefix}.advisories.store")])
@endsection

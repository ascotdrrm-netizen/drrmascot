@extends('layouts.app')

@section('title', 'Add Building/Area - ASCOT DRRMS')
@section('heading', 'Add Building/Area')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.buildings.index") }}'>Back to Buildings/Areas</x-back-link>
@endsection

@section('content')
    @include('building._form', ['formAction' => route("{$routePrefix}.buildings.store")])
@endsection

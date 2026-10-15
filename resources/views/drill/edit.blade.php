@extends('layouts.app')

@section('title', 'Edit Drill Schedule - ASCOT DRRMS')
@section('heading', 'Edit Drill Schedule')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.drills.show", $drill) }}'>Back to drill</x-back-link>
@endsection

@section('content')
    @include('drill._form', ['formAction' => route("{$routePrefix}.drills.update", $drill), 'campuses' => null])
@endsection

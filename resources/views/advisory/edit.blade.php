@extends('layouts.app')

@section('title', 'Edit Disaster Advisory - ASCOT DRRMS')
@section('heading', 'Edit Disaster Advisory')
@section('subheading', 'Update it as new bulletins come in. Mark it Passed when the hazard is over, or Cancelled if it was withdrawn.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.advisories.show", $advisory) }}'>Back to advisory</x-back-link>
@endsection

@section('content')
    @include('advisory._form', ['formAction' => route("{$routePrefix}.advisories.update", $advisory)])
@endsection

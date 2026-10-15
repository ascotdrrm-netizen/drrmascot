@extends('errors.layout')

@section('code', '404')
@section('title', 'Page not found')
@section('message', 'The link may be old, or the record was moved to the trash.')

@section('actions')
            <a href="{{ url('/') }}" class="btn btn-primary">Go to the home page</a>
            <a href="{{ url()->previous() }}" class="btn btn-secondary">Go back</a>
@endsection

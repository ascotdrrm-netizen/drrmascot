@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong on our side')
@section('message', 'The error was logged. Try again in a moment; if it keeps happening, tell the DRRMO Admin.')

@section('actions')
            <a href="{{ url('/') }}" class="btn btn-primary">Go to the home page</a>
            <a href="{{ url()->previous() }}" class="btn btn-secondary">Go back</a>
@endsection

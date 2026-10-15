@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')
@section('message', 'For your security you were signed out after a while without activity. Sign in again to continue.')

@section('actions')
            <a href="{{ route('login') }}" class="btn btn-primary">Sign in again</a>
            <a href="{{ url('/') }}" class="btn btn-secondary">Home</a>
@endsection

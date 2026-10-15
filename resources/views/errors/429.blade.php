@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many tries')
@section('message', 'Please wait a minute, then try again.')

@section('actions')
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn btn-primary"><x-icon name="arrow_back" class="h-4 w-4" /> Go back</a>
            <a href="{{ url('/') }}" class="btn btn-secondary">Home</a>
@endsection

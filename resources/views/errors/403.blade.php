@extends('errors.layout')

@section('code', '403')
@section('title', 'You can’t open this page')
@section('message', 'Your account doesn’t have access to it. If you think it should, ask the DRRMO Admin.')

@section('actions')
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn btn-primary"><x-icon name="arrow_back" class="h-4 w-4" /> Go back</a>
            <a href="{{ url('/') }}" class="btn btn-secondary">Home</a>
@endsection

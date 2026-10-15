@extends('errors.layout')

@section('code', '503')
@section('title', 'Under maintenance')
@section('message', 'The system is being updated and will be back shortly.')

@section('actions')
            <button type="button" onclick="location.reload()" class="btn btn-primary">Try again</button>
@endsection

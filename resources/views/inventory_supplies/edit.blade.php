@extends('layouts.app')

@section('title', 'Edit Supply Item - ASCOT DRRMS')
@section('heading', 'Edit Supply Item')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.inventory-supplies.index') }}">Back to inventory</x-back-link>
@endsection

@section('content')
    @include('inventory_supplies._form', ['formAction' => route('admin.inventory-supplies.update', $supply)])
@endsection

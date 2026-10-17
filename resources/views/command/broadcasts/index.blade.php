@extends('layouts.app')
@section('title', 'Campus broadcasts - ASCOT DRRMS')
@section('heading', 'Campus broadcasts')
@section('content')
<div class="grid gap-6 lg:grid-cols-2">
    <x-card>
        <h2 class="card-title">New draft</h2>
        <p class="mt-2 text-sm text-gray-600">Approved accounts on the chosen campus receive an in-app notice. Review the audience before publishing.</p>
        <form method="POST" action="{{ route($routePrefix.'.broadcasts.store') }}" class="mt-4 space-y-4">
            @csrf
            <input type="hidden" name="client_key" value="{{ old('client_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div><label for="campus_id" class="form-label">Campus</label><select name="campus_id" id="campus_id" required class="form-input">@foreach($campuses as $campus)<option value="{{ $campus->getKey() }}" @selected(old('campus_id') == $campus->getKey())>{{ $campus->campus_name }}</option>@endforeach</select>@error('campus_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror</div>
            @include('command.broadcasts._fields')
            <button type="submit" class="btn btn-primary">Save and preview</button>
        </form>
    </x-card>
    <x-card>
        <h2 class="card-title">Broadcast history</h2>
        <div id="broadcast-list" data-live-refresh class="mt-4 divide-y divide-gray-100">
            @forelse($broadcasts as $item)
                <a href="{{ route($routePrefix.'.broadcasts.show', $item) }}" class="block py-3">
                    <span class="font-semibold">{{ $item->title }}</span>
                    <span class="ml-2 text-sm text-gray-600">{{ $item->status }}</span>
                    <p class="mt-1 text-sm text-gray-500">{{ $item->campus->campus_name }} · {{ ($item->published_at ?? $item->created_at)->format('M j, Y g:i A') }}</p>
                </a>
            @empty<p class="text-sm text-gray-500">No broadcasts yet.</p>@endforelse
            {{ $broadcasts->links() }}
        </div>
    </x-card>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Roll Call - ASCOT DRRMS')
@section('heading', 'Roll Call')
@section('subheading', 'Check who is safe, who needs help, and who has not answered.')
@section('content')
    <x-card class="max-w-3xl mb-6">
        <h2 class="card-title mb-4">Start a safety check-in</h2>
        <p class="form-help mb-4">One roll call can be open per campus. The roster includes approved, email-verified Reporters when it starts.</p>
        <form method="POST" action="{{ route($routePrefix . '.roll-calls.store') }}" data-ajax class="space-y-4">
            @csrf
            <div><label for="title" class="form-label">Title</label><input id="title" name="title" maxlength="150" required class="form-input" value="{{ old('title') }}" placeholder="e.g. Heavy rain safety check"></div>
            <div><label for="campus_id" class="form-label">Campus</label><select id="campus_id" name="campus_id" required class="form-input">@foreach ($campuses as $campus)<option value="{{ $campus->getKey() }}">{{ $campus->campus_name }}</option>@endforeach</select></div>
            <div><label for="instructions" class="form-label">Instructions (optional)</label><textarea id="instructions" name="instructions" maxlength="2000" rows="3" class="form-input">{{ old('instructions') }}</textarea></div>
            <button type="submit" class="btn btn-primary">Start roll call</button>
        </form>
    </x-card>
    <div id="roll-call-list" data-live-refresh class="grid gap-4 sm:grid-cols-2">
        @forelse ($rollCalls as $rollCall)
            <a href="{{ route($routePrefix . '.roll-calls.show', $rollCall) }}" class="card block">
                <p class="text-xs text-gray-600">{{ $rollCall->campus->campus_name }} · {{ $rollCall->closed_at ? 'Closed' : 'Open' }}</p>
                <h2 class="mt-1 font-semibold">{{ $rollCall->title }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ $rollCall->responses_count }} people expected · started {{ $rollCall->started_at->diffForHumans() }}</p>
            </a>
        @empty
            <p class="card text-sm text-gray-600">No roll calls yet. Start one above to check on your campus.</p>
        @endforelse
        <div class="sm:col-span-2">{{ $rollCalls->links() }}</div>
    </div>
@endsection

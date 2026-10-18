@extends('layouts.mobile')
@section('title', 'Campus notice - ASCOT DRRMS')
@section('content')
<a href="{{ route('app.alerts') }}" class="text-sm text-accent-ink">Back to alerts</a>
<article class="mt-4 rounded-xl border border-gray-100 bg-white p-4">
    <p class="text-xs text-gray-600">{{ $broadcast->campus->campus_name }} · {{ $broadcast->published_at->format('M j, Y g:i A') }}</p>
    <h1 class="mt-3 text-xl font-semibold">{{ $broadcast->title }}</h1>
    <p class="mt-4 whitespace-pre-wrap text-sm leading-6">{{ $broadcast->body }}</p>
</article>
@endsection

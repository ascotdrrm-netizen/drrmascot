@extends('layouts.app')

@section('title', 'Notifications - ASCOT DRRMS')
@section('heading', 'Notifications')
@section('subheading', 'New reports and SOS alerts sent to your office and campus.')

@section('content')
    <x-card>
        @forelse ($notifications as $notification)
            <form method="POST" action="{{ route($routePrefix . '.notifications.open', $notification) }}"
                  class="border-b border-gray-100 py-3 last:border-0">
                @csrf
                <button type="submit" class="flex w-full items-start gap-3 text-left">
                    <x-icon :name="$notification->kind === 'sos' ? 'sos' : 'inbox'" class="mt-0.5 h-5 w-5 {{ $notification->kind === 'sos' ? 'text-red-700' : 'text-accent-ink' }}" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm {{ $notification->read_at ? 'text-gray-600' : 'font-semibold text-gray-900' }}">{{ $notification->message }}</span>
                        <span class="block text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                    @unless ($notification->read_at) <span class="mt-1 h-2 w-2 rounded-full bg-red-600" aria-label="Unread"></span> @endunless
                </button>
            </form>
        @empty
            <p class="text-sm text-gray-600">No notifications yet.</p>
        @endforelse
    </x-card>
@endsection

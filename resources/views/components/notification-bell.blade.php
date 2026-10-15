@props(['prefix', 'count' => 0])
<a href="{{ route($prefix . '.notifications.index') }}" class="icon-btn relative" aria-label="Notifications"
   data-notification-bell data-count-url="{{ route('api.command.notifications') }}">
    <x-icon name="notifications" />
    <span data-notification-count @if (! $count) hidden style="display: none" @endif class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-xs font-bold text-snow">{{ $count }}</span>
    <span class="sr-only" aria-live="polite" data-notification-announcement></span>
</a>

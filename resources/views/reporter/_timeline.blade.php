{{-- A vertical status timeline: done steps filled, the next one hollow. --}}
<ol class="mt-3 space-y-0">
    @foreach ($steps as $step)
        @php $done = $step['done'] ?? true; @endphp
        <li class="relative flex gap-3 pb-5 last:pb-0">
            @unless ($loop->last)
                <span class="absolute left-[11px] top-6 h-full w-0.5 {{ $done ? 'bg-accent' : 'bg-gray-200' }}" aria-hidden="true"></span>
            @endunless
            <span class="relative z-10 mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $done ? 'bg-accent text-on-accent' : 'border-2 border-gray-300 bg-white' }}">
                @if ($done)<x-icon name="check" class="h-4 w-4" />@endif
            </span>
            <div class="min-w-0">
                <p class="text-sm font-semibold {{ $done ? '' : 'text-gray-600' }}">{{ $step['status'] }}</p>
                <p class="text-sm text-gray-600">{{ $step['note'] }}</p>
                @if ($step['at'])<p class="text-xs text-gray-600">{{ $step['at']->format('g:i A') }} · {{ $step['at']->diffForHumans() }}</p>@endif
            </div>
        </li>
    @endforeach
</ol>

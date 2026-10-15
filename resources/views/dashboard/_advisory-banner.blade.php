{{--
    The Disaster Advisory warning, on both dashboards: every advisory that is
    Active and not past its valid-until time, for the campus in view (an
    all-campus advisory shows on every campus). It disappears by itself once
    Valid until passes, or when the advisory is marked Passed or Cancelled.
    $liveAdvisories: DashboardController::liveAdvisories()
--}}
@if ($liveAdvisories->isNotEmpty())
    <div class="mb-6 rounded-lg border border-amber-300 border-l-4 border-l-amber-500 bg-amber-50 p-4 text-sm text-amber-950" role="alert" data-advisory-banner>
        <p class="font-semibold">
            {{ $liveAdvisories->count() === 1 ? 'Disaster advisory in effect' : $liveAdvisories->count() . ' disaster advisories in effect' }}
        </p>
        <ul class="mt-2 space-y-2">
            @foreach ($liveAdvisories as $advisory)
                <li class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4" data-advisory-banner-item="{{ $advisory->advisory_id }}">
                    <p>
                        <span class="font-semibold">{{ $advisory->hazard_label }}</span>
                        @if ($advisory->headline_measurement)
                            — {{ $advisory->headline_measurement }}
                        @endif
                        · {{ $advisory->campus_label }}
                        <span class="block text-amber-900">
                            {{ $advisory->headline }}.
                            {{ $advisory->expected_at ? 'Expected ' . $advisory->expected_at->format('M j, g:i A') . '.' : 'Not forecast to a time.' }}
                            Valid until {{ $advisory->valid_until->format('M j, g:i A') }}.
                        </span>
                    </p>
                    <a href="{{ route("{$routePrefix}.advisories.show", $advisory->advisory_id) }}" class="link shrink-0 font-medium">View advisory</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif

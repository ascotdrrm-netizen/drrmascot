{{--
    UNUSED — set aside for later use, not included anywhere right now.

    This was the right-hand column of a two-column "Latest Updates" layout
    (Facebook feed on the left, this panel filling the remaining width on
    the right). The landing page currently uses the simpler single-column
    design again (feed only, centered) — see landing.blade.php and
    landing/_latest-updates.blade.php.

    Expects: $campuses (a collection of Campus models, e.g. from
    LandingController@index) for the "Our Campuses" card.

    Still-placeholder data in the "Emergency Hotlines" card — fill these
    in before this partial is reintroduced anywhere:
      - "ASCOT DRRMO Office" phone number
      - "Campus Security" phone number
--}}
<div class="grid flex-1 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1">
    <div class="card">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-blue-50 text-blue-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5l13-5v13l-13-5v-3zM16 8.5a3.5 3.5 0 010 6M18.5 6a6.5 6.5 0 010 11" />
            </svg>
        </div>
        <h3 class="card-title mb-1">Follow ASCOT DRRMO</h3>
        <p class="mb-4 text-sm text-gray-600">Follow the official Facebook page for advisories, drill schedules, and emergency updates.</p>
        <a href="https://www.facebook.com/profile.php?id=61579337100805" target="_blank" rel="noopener noreferrer"
           class="btn btn-primary btn-sm">
            Visit Facebook Page
        </a>
    </div>

    <div class="card">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-red-50 text-red-600">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path stroke-linecap="round" d="M12 8v5M12 16h.01" />
            </svg>
        </div>
        <h3 class="card-title mb-1">Emergency Hotlines</h3>
        <ul class="space-y-1.5 text-sm text-gray-600">
            <li class="flex items-center justify-between">
                <span>National Emergency Hotline</span>
                <span class="font-semibold text-gray-900">911</span>
            </li>
            <li class="flex items-center justify-between">
                <span>ASCOT DRRMO Office</span>
                <span class="italic text-gray-600">[to be provided]</span>
            </li>
            <li class="flex items-center justify-between">
                <span>Campus Security</span>
                <span class="italic text-gray-600">[to be provided]</span>
            </li>
        </ul>
    </div>

    <div class="card">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-green-50 text-green-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-7.5 7-12a7 7 0 10-14 0c0 4.5 7 12 7 12z" />
                <circle cx="12" cy="9" r="2.5" />
            </svg>
        </div>
        <h3 class="card-title mb-1">Our Campuses</h3>
        <ul class="space-y-1.5 text-sm text-gray-600">
            @foreach ($campuses as $campus)
                <li>{{ $campus->campus_name }}</li>
            @endforeach
        </ul>
    </div>

    <div class="card">
        <div class="mb-4 flex h-11 w-11 items-center justify-center rounded-full bg-amber-50 text-amber-600">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
            </svg>
        </div>
        <h3 class="card-title mb-1">Be Prepared</h3>
        <ul class="space-y-1.5 text-sm text-gray-600">
            <li>Know your building's evacuation route.</li>
            <li>Keep a go-bag ready at all times.</li>
            <li>Follow official advisories only.</li>
            <li>Join your campus drills.</li>
        </ul>
    </div>
</div>

{{--
    Guides for the emergency-response tools and the remaining admin pages
    (thesis panel round 2: "wala pa yung ibang guide — roll call, dispatch…").
    Included by help/index.blade.php, which defines $h2, $p, $list, $strong,
    $isAdmin and $routePrefix. Keep each guide: what it is for, the steps,
    then tips.
--}}
@php
    $go = fn (string $name, string $label) => '<a class="link" href="' . e(route($routePrefix . '.' . $name)) . '">' . e($label) . '</a>';
    $steps = 'mt-4 list-decimal space-y-1 pl-6 text-sm leading-6 text-gray-700';
@endphp

<x-card class="scroll-mt-24" id="sos" data-help-section="sos">
    <h2 class="{{ $h2 }}">SOS Console</h2>
    <p class="{{ $p }} mt-4">When a student or staff member presses <span class="{{ $strong }}">SOS</span> in the phone app, it appears here at once, the person waiting longest first. The office is also notified, and staff get an email (and a text when SMS is set up). Open: {!! $go('command.sos', 'SOS Console') !!}.</p>
    <ol class="{{ $steps }}">
        <li><span class="{{ $strong }}">Acknowledge</span> it right away. The person's phone shows that the DRRM Office acknowledged the SOS, so they know someone saw it.</li>
        <li>Call the person on the number shown. Use the map link when they shared their location.</li>
        <li>Send help from {!! $go('dispatch.index', 'Dispatch') !!}; the SOS changes to <span class="{{ $strong }}">Responding</span>. Use <span class="{{ $strong }}">Escalate</span> to call outside help (BFP, PNP, MDRRMO, hospital) for anything campus responders can't handle.</li>
        <li><span class="{{ $strong }}">Resolve</span> it when the person is safe. Their phone shows the response as completed.</li>
    </ol>
    <ul class="{{ $list }} mt-4">
        <li>The order is fixed: New → Acknowledged → Responding → Resolved. A step can't be skipped or undone.</li>
        <li>Keep this page open during a typhoon or drill; it checks for new SOS every few seconds.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="inbox" data-help-section="inbox">
    <h2 class="{{ $h2 }}">Reports Inbox</h2>
    <p class="{{ $p }} mt-4">Hazards reported from the phone app (a fallen tree, exposed wiring, flooding, bullying and the like) with photos, the place or GPS pin, and how many are hurt. Open: {!! $go('command.inbox', 'Reports Inbox') !!}.</p>
    <ol class="{{ $steps }}">
        <li>Open a report and check it: the photos, the location accuracy and the description.</li>
        <li><span class="{{ $strong }}">Verify</span> a real report, or <span class="{{ $strong }}">Reject</span> a false or duplicate one with a short reason; the reporter sees it.</li>
        <li>Send help from {!! $go('dispatch.index', 'Dispatch') !!}. Dispatching a new report also verifies it.</li>
        <li><span class="{{ $strong }}">Resolve</span> it when the work is done. Each change shows on the reporter's timeline in the app.</li>
    </ol>
    <ul class="{{ $list }} mt-4">
        <li>Order: New → Verified → Responding → Resolved, or Rejected from New or Verified.</li>
        <li>A serious report can also be recorded as an Incident, for damage reports and the consolidated report.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="dispatch" data-help-section="dispatch">
    <h2 class="{{ $h2 }}">Dispatch</h2>
    <p class="{{ $p }} mt-4">Assigns campus responders (clinic, security, maintenance, the response team) to open reports and SOS, and records when they arrive and finish. Open: {!! $go('dispatch.index', 'Dispatch') !!}.</p>
    <ol class="{{ $steps }}">
        <li>First time only: under <span class="{{ $strong }}">Add a campus responder</span>, enter each responder's name, team and contact number. Mark who is on duty.</li>
        <li>Under <span class="{{ $strong }}">Assign a responder</span>, pick the report or SOS and an available responder, add a note, and press <span class="{{ $strong }}">Assign responder</span>. Then call the responder to confirm.</li>
        <li>When the responder reaches the place, mark the assignment <span class="{{ $strong }}">On scene</span>.</li>
        <li>When they finish, mark it <span class="{{ $strong }}">Completed</span>; this frees the responder. Then close the report or SOS itself in the Reports Inbox or SOS Console.</li>
    </ol>
    <ul class="{{ $list }} mt-4">
        <li>A responder busy on one assignment can't be given another until it is completed or cancelled.</li>
        <li>Closing a report or SOS cancels any assignment still open on it.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="rollcall" data-help-section="rollcall">
    <h2 class="{{ $h2 }}">Roll Call</h2>
    <p class="{{ $p }} mt-4">A safety check-in for a whole campus after an earthquake, during a typhoon or at the end of a drill: everyone answers <span class="{{ $strong }}">I'm safe</span> or <span class="{{ $strong }}">I need help</span> in the phone app. Open: {!! $go('roll-calls.index', 'Roll Call') !!}.</p>
    <ol class="{{ $steps }}">
        <li>Under <span class="{{ $strong }}">Start a safety check-in</span>, give it a title (for example "Earthquake check-in, Oct 21") and short instructions, then press <span class="{{ $strong }}">Start roll call</span>.</li>
        <li>Every approved Reporter of the campus gets a notice and a Check in screen in the app. The roster is fixed when it starts.</li>
        <li>Open the roll call to watch the counts: safe, needs help, not answered. "Needs help" answers show the note and the GPS location they shared; send help from Dispatch.</li>
        <li>Call or look for the people who have not answered.</li>
        <li>Press <span class="{{ $strong }}">Close roll call</span> when everyone is accounted for. <span class="{{ $strong }}">Export</span> downloads the list (name, number, answer, note, time) for the After Activity Report.</li>
    </ol>
    <ul class="{{ $list }} mt-4">
        <li>Only one roll call can be open per campus at a time.</li>
        <li>People who registered after it started are not on its roster.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="status" data-help-section="status">
    <h2 class="{{ $h2 }}">Campus Status</h2>
    <p class="{{ $p }} mt-4">One line that tells a campus what is happening now: <span class="{{ $strong }}">Normal</span>, <span class="{{ $strong }}">Advisory in effect</span>, <span class="{{ $strong }}">Classes suspended</span> or <span class="{{ $strong }}">Evacuate</span>. It shows on the public home page and at the top of the phone app. Open: {!! $go('command.campus-status', 'Campus Status') !!}.</p>
    <ol class="{{ $steps }}">
        <li>Pick the status for the campus{{ $isAdmin ? ' (the Admin can set any campus)' : '' }}.</li>
        <li>Under <span class="{{ $strong }}">What people should know</span>, write the instruction: where to go, what to avoid, until when.</li>
        <li>Press <span class="{{ $strong }}">Publish status</span>. Reporters and staff of the campus get a notice.</li>
    </ol>
    <ul class="{{ $list }} mt-4"><li>Set it back to Normal when the situation is over.</li></ul>
</x-card>

<x-card class="scroll-mt-24" id="broadcasts" data-help-section="broadcasts">
    <h2 class="{{ $h2 }}">Broadcasts and notifications</h2>
    <p class="{{ $p }} mt-4">A <span class="{{ $strong }}">broadcast</span> is a message to everyone on a campus: always in the app, and also by email or SMS when you tick them. <span class="{{ $strong }}">Notifications</span> (the bell) are what the system sends you: new reports, SOS, roll-call answers. Open: {!! $go('broadcasts.index', 'Campus broadcasts') !!} · {!! $go('notifications.index', 'Notifications') !!}.</p>
    <ol class="{{ $steps }}">
        <li>Write the subject and message, choose email and/or SMS (the SMS text has its own short box), then <span class="{{ $strong }}">Save and preview</span>.</li>
        <li>Check the preview: how many people get it on each channel, and who can't be reached (no verified number or email) and why.</li>
        <li>Press <span class="{{ $strong }}">Publish</span>. The delivery history shows what was sent, queued or rejected; <span class="{{ $strong }}">Retry rejected deliveries</span> sends the failed ones again.</li>
    </ol>
    <ul class="{{ $list }} mt-4"><li>Don't start an SMS with "TEST": the SMS provider discards it.</li></ul>
</x-card>

<x-card class="scroll-mt-24" id="hotlines" data-help-section="hotlines">
    <h2 class="{{ $h2 }}">Hotlines</h2>
    <p class="{{ $p }} mt-4">The emergency numbers on the home page, the phone app (they work without signal once opened) and every error page. {{ $isAdmin ? 'The Admin can edit every group.' : "You can edit your campus's group." }} Open: {!! $go('hotlines.index', 'Hotlines') !!}.</p>
    <ol class="{{ $steps }}">
        <li>Press <span class="{{ $strong }}">Edit</span> on a group. One office per row; put several numbers on separate lines.</li>
        <li>Leave the numbers blank to show "to be provided". Save.</li>
    </ol>
</x-card>

<x-card class="scroll-mt-24" id="archive" data-help-section="archive">
    <h2 class="{{ $h2 }}">Archive</h2>
    <p class="{{ $p }} mt-4">Everything deleted, from every module, in one list: buildings, incidents, advisories and drills. Open: {!! $go('archive.index', 'Archive') !!}.</p>
    <ul class="{{ $list }} mt-4">
        <li><span class="{{ $strong }}">Restore</span> brings a record back exactly as it was, with its photos and attachments.</li>
        <li>Each list's own <span class="{{ $strong }}">View Trash</span> shows the same records for that module. See <a class="link" href="#trash">Deleting and Trash</a>.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="reporters" data-help-section="reporters">
    <h2 class="{{ $h2 }}">Reporter accounts (students and staff)</h2>
    <p class="{{ $p }} mt-4">Students and staff sign up in the phone app with their campus, school and ID number, then verify their email. {{ $isAdmin ? 'Coordinators validate them for their campus; the Admin can also approve them under User Management.' : 'You validate the ones for your campus.' }}</p>
    <ol class="{{ $steps }}">
        @if ($isAdmin)
            <li>Open <span class="{{ $strong }}">User Management</span> and filter by Reporter to see who is waiting.</li>
        @else
            <li>Open {!! $go('reporters.pending', 'Pending Reporters') !!}.</li>
        @endif
        <li>Check the name, ID number and school against the school's records.</li>
        <li><span class="{{ $strong }}">Validate</span> a real student or staff member; reject anyone you can't confirm.</li>
    </ol>
    <ul class="{{ $list }} mt-4"><li>Only validated, email-verified Reporters can send reports and SOS, and are counted in roll calls.</li></ul>
</x-card>

@if ($isAdmin)
    <x-card class="scroll-mt-24" id="settings" data-help-section="settings">
        <h2 class="{{ $h2 }}">Settings</h2>
        <p class="{{ $p }} mt-4">The schools and courses of each campus, and who signs the PDF reports. Open: {!! $go('settings.index', 'Settings') !!}.</p>
        <ul class="{{ $list }} mt-4">
            <li><span class="{{ $strong }}">Schools</span>: add or rename a school; it then shows in the sign-up and staff forms of that campus.</li>
            <li><span class="{{ $strong }}">Report signatory</span>: the name and position printed under "Noted by" on every PDF report.</li>
            <li><span class="{{ $strong }}">Backup</span>: download a copy of all records regularly. <span class="{{ $strong }}">Clear records</span> empties the system and asks for confirmation first.</li>
        </ul>
    </x-card>
@endif

<x-card class="scroll-mt-24" id="activity" data-help-section="activity">
    <h2 class="{{ $h2 }}">Activity Log</h2>
    <p class="{{ $p }} mt-4">{{ $isAdmin ? 'Who did what and when, for every account: records added, changed or deleted, sign-ins, downloads and dispatches.' : 'Everything you did in the system: records added, changed or deleted, downloads and dispatches. It shows your own entries only.' }} Open: {!! $go('activity-log.index', 'Activity Log') !!}.</p>
    <ul class="{{ $list }} mt-4">
        <li>Filter by date, action or module; <span class="{{ $strong }}">Download</span> prints the filtered list as a PDF.</li>
        <li>Use it to check a record's history, for example who scheduled a drill and when.</li>
    </ul>
</x-card>

<x-card class="scroll-mt-24" id="install" data-help-section="install">
    <h2 class="{{ $h2 }}">Install on your phone or computer</h2>
    <p class="{{ $p }} mt-4">This dashboard installs as an app, with its own icon and window, like the students' phone app.</p>
    <ul class="{{ $list }} mt-4">
        <li><span class="{{ $strong }}">Android or a computer (Chrome, Edge)</span>: press <span class="{{ $strong }}">Install app</span> in the side menu, or the install icon in the address bar.</li>
        <li><span class="{{ $strong }}">iPhone or iPad (Safari)</span>: tap Share, then <span class="{{ $strong }}">Add to Home Screen</span>.</li>
        <li>It still needs the internet to show records; without signal it shows the hotlines.</li>
    </ul>
</x-card>

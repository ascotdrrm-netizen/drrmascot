@extends('layouts.app')

@section('title', 'Help - ASCOT DRRMS')
@section('heading', 'Help')
@section('subheading', $isAdmin
    ? 'How ASCOT-DRRMS works, in plain words — shown for the DRRMO Admin.'
    : 'How ASCOT-DRRMS works, in plain words — shown for campus coordinators.')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.dashboard") }}'>Back to dashboard</x-back-link>
@endsection

{{--
    One page, both roles; sections and sentences that only matter to one role
    are shown to that role only. Every definition comes from config/glossary.php,
    the same sentences the info tips and form hints use.
--}}
@php
    $sections = [
        'start' => 'Getting started',
        'roles' => 'Who does what',
        'modules' => 'The modules',
        'disasters' => 'Disaster advisories',
        'drills' => 'Drills',
        'terms' => 'Risk levels and statuses',
        'reports' => 'Reports and downloads',
        'trash' => 'Deleting and Trash',
    ];
    $termTable = function (string $title, array $terms) {
        return ['title' => $title, 'terms' => $terms];
    };
    $termGroups = [
        $termTable('Incident status', config('glossary.incident_status')),
        $termTable('Damage level', config('glossary.damage_level')),
        $termTable('Alert delivery status', config('glossary.delivery_status')),
    ];
    $h2 = 'section-title';
    $p = 'text-sm leading-6 text-gray-700';
    $list = 'list-disc pl-6 text-sm leading-6 text-gray-700';
    $strong = 'font-semibold text-gray-900';
@endphp

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        {{-- On this page --}}
        <nav class="lg:col-span-1" aria-label="On this page">
            <x-card class="lg:sticky lg:top-6">
                <p class="eyebrow">On this page</p>
                <ul class="mt-4">
                    @foreach ($sections as $id => $label)
                        <li><a href="#{{ $id }}" class="link inline-flex min-h-[2.75rem] items-center text-sm sm:min-h-[2rem]">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </x-card>
        </nav>

        <div class="min-w-0 space-y-6 lg:col-span-3">
            <x-card class="scroll-mt-24" id="start" data-help-section="start">
                <h2 class="{{ $h2 }}">Getting started</h2>
                <p class="{{ $p }} mt-4">
                    ASCOT-DRRMS is the disaster risk reduction and management record of ASCOT: which buildings have hazards,
                    what incidents happened and what they damaged, and the drills each campus holds.
                </p>
                @if ($isAdmin)
                    <ol class="mt-4 list-decimal pl-6 text-sm leading-6 text-gray-700">
                        <li>Check <span class="{{ $strong }}">User Management</span>: every coordinator should have the right campus and, ideally, a school.</li>
                        <li>Schedule a drill under <span class="{{ $strong }}">Drills → + Schedule Drill</span>. You can pick several campuses at once.</li>
                        <li>Watch the dashboard's <span class="{{ $strong }}">Drills to document</span> and <span class="{{ $strong }}">Needs attention</span> lists.</li>
                        <li>Use the <span class="{{ $strong }}">Consolidated Report</span> for a summary of every campus for any period.</li>
                    </ol>
                @else
                    <ol class="mt-4 list-decimal pl-6 text-sm leading-6 text-gray-700">
                        <li>Open <span class="{{ $strong }}">Buildings/Areas</span>. If a building on your campus is missing, add it.</li>
                        <li>Open a building and use <span class="{{ $strong }}">+ Record an inspection</span> to note the hazards you find.</li>
                        <li>When something happens — a typhoon, earthquake, flood or fire — use <span class="{{ $strong }}">+ Report incident</span>, then add a damage report for each building it affected.</li>
                        <li>Check <span class="{{ $strong }}">Drills</span> for your campus’s drill calendar. After each drill, add its documentation and After Activity Report.</li>
                    </ol>
                @endif
                <p class="{{ $p }} mt-4">Anywhere you see <span aria-hidden="true">ⓘ</span><span class="sr-only">an info button</span> next to a word, tap it for a one-line explanation.</p>
            </x-card>

            <x-card class="scroll-mt-24" id="roles" data-help-section="roles">
                <h2 class="{{ $h2 }}">Who does what</h2>
                <dl class="mt-4 space-y-4 text-sm leading-6">
                    <div>
                        <dt class="{{ $strong }}">DRRMO Admin{{ $isAdmin ? ' (you)' : '' }}</dt>
                        <dd class="text-gray-700">Sees every campus. Schedules drills for any campus, sends SMS alerts about incidents, manages user accounts, and makes backups.</dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Campus coordinator{{ $isAdmin ? '' : ' (you)' }}</dt>
                        <dd class="text-gray-700">Works on one campus. Adds buildings/areas, records hazard inspections, reports incidents and damage, and schedules, documents and reports on the campus’s drills.</dd>
                    </div>
                </dl>
                <h3 class="card-title mt-6">Who can see what</h3>
                <ul class="{{ $list }} mt-4">
                    <li><span class="{{ $strong }}">Buildings/Areas</span> are shared by every coordinator of the same campus.</li>
                    <li><span class="{{ $strong }}">Inspections and incidents</span> are seen only by the coordinator who recorded them and by the DRRMO. Damage reports and alerts follow their incident.</li>
                    <li><span class="{{ $strong }}">Drills</span>, their documentation and After Activity Reports are seen by every coordinator of the campus, like buildings.</li>
                    <li><span class="{{ $strong }}">School</span>: {{ config('glossary.school') }}</li>
                    @if ($isAdmin)
                        <li>Records whose coordinator account was deleted stay in the system, visible to the DRRMO only.</li>
                    @endif
                </ul>
            </x-card>

            <x-card class="scroll-mt-24" id="modules" data-help-section="modules">
                <h2 class="{{ $h2 }}">The modules</h2>
                <dl class="mt-4 space-y-4 text-sm leading-6">
                    <div>
                        <dt class="{{ $strong }}">Dashboard</dt>
                        <dd class="text-gray-700">
                            Counts for the chosen time range, compared with the period before, plus what needs attention right now.
                            @unless ($isAdmin) {{ config('glossary.kpi_coordinator_scope') }} @endunless
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Buildings/Areas and hazard inspections</dt>
                        <dd class="text-gray-700">
                            Each building or open area on a campus, with its hazard type and risk level. An <em>inspection</em> records
                            the hazards someone found on a given date, with a risk level, notes, a photo and a map location.
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Incidents and damage reports</dt>
                        <dd class="text-gray-700">
                            An <em>incident</em> is something that happened on a campus — a disaster-related event or a campus
                            emergency: what kind (its <em>incident type</em>), when, and its status. A <em>damage report</em>
                            records what it did to one building or area — damage level, casualties and a rough cost — so an incident
                            that hit three buildings has three damage reports.
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Advisories</dt>
                        <dd class="text-gray-700">Disaster advisories: warnings received before or during an event, shown on the dashboards while they are in effect. See <a href="#disasters" class="link">Disaster advisories</a>.</dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">SMS alerts</dt>
                        <dd class="text-gray-700">
                            @if ($isAdmin)
                                From an incident's page, <span class="{{ $strong }}">Send Alert</span> texts everyone on that campus who has a contact number.
                                A sent SMS cannot be recalled. Each alert is listed under the incident's Alert History with its delivery status.
                            @else
                                The DRRMO can text everyone on a campus about an incident. You receive these alerts if your profile has a contact number.
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Drills</dt>
                        <dd class="text-gray-700">A month calendar of drills, each with its documentation and After Activity Report. See <a href="#drills" class="link">Drills</a>.</dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">{{ $isAdmin ? 'Activity Log' : 'My Activity Log' }}</dt>
                        <dd class="text-gray-700">
                            {{ $isAdmin
                                ? 'Every sign-in, change and download by anyone, newest first. Entries cannot be edited or deleted.'
                                : 'Everything you have done in the system: sign-ins, records you added, changed or deleted, and downloads.' }}
                        </dd>
                    </div>
                    @if ($isAdmin)
                        <div>
                            <dt class="{{ $strong }}">User Management</dt>
                            <dd class="text-gray-700">
                                People create their own accounts on the Register page. A new account can't sign in until you approve it: it waits under
                                <span class="{{ $strong }}">Waiting for approval</span> at the top of User Management, and your dashboard tells you when someone
                                is waiting. Rejecting removes the registration. Here you also correct their name, campus, school or role, or delete an account.
                                Deleting is permanent; the incidents and inspections that person recorded stay, visible to you only.
                            </dd>
                        </div>
                        <div>
                            <dt class="{{ $strong }}">Backup &amp; Restore</dt>
                            <dd class="text-gray-700">
                                Download a copy of the whole database, or restore one. Both ask for the <em>Data Privacy Password</em>, which is set by
                                the system administrator on the server. Restoring replaces every record in the system with the backup's.
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="{{ $strong }}">My Profile</dt>
                        <dd class="text-gray-700">Your name, contact number (used for SMS alerts), photo and password.</dd>
                    </div>
                </dl>
            </x-card>

            <x-card class="scroll-mt-24" id="disasters" data-help-section="disasters">
                <h2 class="{{ $h2 }}">Disaster advisories</h2>
                <p class="{{ $p }} mt-4">
                    An <em>advisory</em> is a warning received <em>before or during</em> an event — a PAGASA typhoon bulletin, a PHIVOLCS earthquake report,
                    a DRRMO flood or landslide warning. What actually happened, and the damage it caused, is recorded under <span class="{{ $strong }}">Incidents</span>.
                    There is no automatic weather or earthquake feed: someone types in the details from the bulletin they received.
                </p>
                <ol class="mt-4 list-decimal pl-6 text-sm leading-6 text-gray-700">
                    <li><span class="{{ $strong }}">Issue</span> it from <span class="{{ $strong }}">Advisories</span>: the hazard, its measurements (only the ones that fit that hazard), the source, when it is expected and how long it is valid. {{ $isAdmin ? 'You can issue one for a single campus or for all campuses.' : 'It is for your campus.' }}</li>
                    <li>While it is <span class="{{ $strong }}">In effect</span> it shows as a warning at the top of every dashboard for that campus, until its valid-until time.</li>
                    <li><span class="{{ $strong }}">Print advisory memo</span> gives a notice on the ASCOT letterhead for posting and sharing with students and staff.</li>
                    <li>If the event hits, open the advisory and use <span class="{{ $strong }}">Record what happened</span>: the incident form opens with the hazard and campus filled in, and the incident is linked to the advisory. Landslides and storm surges are recorded as incident type Other, with the name filled in.</li>
                    <li>When it is over, edit it and mark it <span class="{{ $strong }}">Passed</span>.</li>
                </ol>
                <ul class="{{ $list }} mt-4">
                    <li>Advisories are for the whole campus: every coordinator of a campus sees its advisories, and everyone sees the all-campus ones.</li>
                    <li>Only {{ $isAdmin ? 'you, or the coordinator who issued an advisory,' : 'the person who issued an advisory, or the DRRMO,' }} can change or delete it.</li>
                    <li>SMS alerts are not sent from advisories; they are sent from an incident's page.</li>
                </ul>
                <dl class="mt-4 divide-y divide-gray-100 text-sm leading-6">
                    @foreach (config('glossary.advisory_status') as $label => $meaning)
                        <div class="py-3"><dt class="{{ $strong }}">{{ $label }}</dt><dd class="text-gray-700">{{ $meaning }}</dd></div>
                    @endforeach
                    <div class="py-3"><dt class="{{ $strong }}">Source</dt><dd class="text-gray-700">{{ config('glossary.advisory_source') }}</dd></div>
                    <div class="py-3"><dt class="{{ $strong }}">Valid until</dt><dd class="text-gray-700">{{ config('glossary.advisory_valid_until') }}</dd></div>
                </dl>
                <h3 class="card-title mt-6">Measurement terms</h3>
                <p class="{{ $p }} mt-1" data-official-terms>These are official PAGASA and PHIVOLCS terms. The system doesn't define them; for what each level means, go by the issuing agency.</p>
                <dl class="mt-2 divide-y divide-gray-100 text-sm leading-6">
                    @foreach (config('glossary.measurement_terms') as $term => $text)
                        <div class="py-3"><dt class="{{ $strong }}">{{ $term }}</dt><dd class="text-gray-700">{{ $text }}</dd></div>
                    @endforeach
                </dl>
            </x-card>

            <x-card class="scroll-mt-24" id="drills" data-help-section="drills">
                <h2 class="{{ $h2 }}">Drills</h2>
                <p class="{{ $p }} mt-4">The Drills page is a month calendar, coloured by drill type; switch to <span class="{{ $strong }}">List</span> for rows. On a phone it shows the month as a list grouped by date.</p>
                <ol class="mt-4 list-decimal pl-6 text-sm leading-6 text-gray-700">
                    <li><span class="{{ $strong }}">Schedule</span> the drill: type, date, time, venue, partner agency and objectives. {{ $isAdmin ? 'You can pick several campuses at once — one drill is created for each.' : 'It is for your campus.' }}</li>
                    <li>Before it, <span class="{{ $strong }}">Print announcement</span> gives a notice of the date, time and venue for students and staff.</li>
                    <li>After it, open the drill and add its <span class="{{ $strong }}">documentation</span>: when it was held, what happened, roughly how many took part, with photos or PDFs.</li>
                    <li>Separately, file its <span class="{{ $strong }}">After Activity Report</span>: objectives, activities, participants, results, problems and recommendations, with its own photos or PDFs.</li>
                </ol>
                <ul class="{{ $list }} mt-4">
                    <li>The documentation and the After Activity Report are two different submissions with their own files and their own printouts. Each drill has at most one of each; open it again to edit.</li>
                    <li>Every coordinator of a campus sees all of that campus’s drills and can fill in either form; the person who submits is recorded.</li>
                    <li>Only {{ $isAdmin ? 'you, or the coordinator who scheduled a drill,' : 'the person who scheduled a drill, or the DRRMO,' }} can change or delete its schedule.</li>
                    <li>Attached files are private: only signed-in staff of that campus and the DRRMO can open them.</li>
                </ul>
                <dl class="mt-4 text-sm leading-6">
                    <div><dt class="inline {{ $strong }}">Documentation:</dt> <dd class="inline text-gray-700">{{ config('glossary.drill_documentation') }}</dd></div>
                    <div><dt class="inline {{ $strong }}">After Activity Report:</dt> <dd class="inline text-gray-700">{{ config('glossary.after_activity_report') }}</dd></div>
                </dl>
            </x-card>

            <x-card class="scroll-mt-24" id="terms" data-help-section="terms">
                <h2 class="{{ $h2 }}">Risk levels and statuses</h2>
                <div class="mt-4">
                    <h3 class="card-title">Risk level (Low, Medium, High)</h3>
                    {{-- PLACEHOLDER: replace with the risk checklist's definitions when it lands. --}}
                    <p class="{{ $p }}" data-risk-placeholder>{{ config('glossary.risk_level') }}</p>
                </div>
                @foreach ($termGroups as $group)
                    <div class="mt-6">
                        <h3 class="card-title">{{ $group['title'] }}</h3>
                        <dl class="divide-y divide-gray-100 text-sm">
                            @foreach ($group['terms'] as $term => $meaning)
                                <div class="flex flex-col gap-x-4 py-4 sm:flex-row">
                                    <dt class="sm:w-40 sm:shrink-0"><x-badge :value="$term" /></dt>
                                    <dd class="leading-6 text-gray-700">{{ $meaning }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
                <div class="mt-6">
                    <h3 class="card-title">Other words you'll see</h3>
                    <dl class="divide-y divide-gray-100 text-sm leading-6">
                        <div class="py-4"><dt class="{{ $strong }}">Not assigned</dt><dd class="text-gray-700">{{ config('glossary.not_assigned') }}</dd></div>
                        <div class="py-4"><dt class="{{ $strong }}">High-risk findings open</dt><dd class="text-gray-700">{{ config('glossary.kpi.open_high_risk') }}</dd></div>
                        <div class="py-4"><dt class="{{ $strong }}">Casualties</dt><dd class="text-gray-700">{{ config('glossary.casualty_count') }}</dd></div>
                        <div class="py-4"><dt class="{{ $strong }}">Recipients</dt><dd class="text-gray-700">{{ config('glossary.recipients') }}</dd></div>
                        <div class="py-4"><dt class="{{ $strong }}">Partner agency</dt><dd class="text-gray-700">{{ config('glossary.partner_agency') }}</dd></div>
                    </dl>
                </div>
            </x-card>

            <x-card class="scroll-mt-24" id="reports" data-help-section="reports">
                <h2 class="{{ $h2 }}">Reports and downloads</h2>
                <dl class="mt-4 space-y-4 text-sm leading-6">
                    <div>
                        <dt class="{{ $strong }}">Download PDF (one record)</dt>
                        <dd class="text-gray-700">On a building, inspection or incident: a printable report of that one record. A drill has two: its documentation and its After Activity Report.</dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Generate Report (one module, one period)</dt>
                        <dd class="text-gray-700">On the Buildings/Areas, Incidents and Drills pages: pick a week, month, quarter, year or custom dates and get that module's summary and records for the period as a PDF on the ASCOT letterhead. On Drills, choose the schedule, all documentation, or all After Activity Reports — three separate reports.</dd>
                    </div>
                    <div>
                        <dt class="{{ $strong }}">Consolidated Report</dt>
                        <dd class="text-gray-700">
                            {{ $isAdmin
                                ? 'Every module summed up for one period, per campus and per school, with changes from the period before. Download it as a PDF.'
                                : 'Your own records in every module, summed up for one period, with changes from the period before. Download it as a PDF.' }}
                        </dd>
                    </div>
                </dl>
            </x-card>

            <x-card class="scroll-mt-24" id="trash" data-help-section="trash">
                <h2 class="{{ $h2 }}">Deleting and Trash</h2>
                <p class="{{ $p }} mt-4">{{ config('glossary.trash') }}</p>
                <ul class="{{ $list }} mt-4">
                    <li><span class="{{ $strong }}">Go to Trash (can be restored):</span> buildings/areas with their inspections, incidents with their damage reports and alerts, disaster advisories, and drills with their documentation, After Activity Report and files. Photos stay with them and come back on restore.</li>
                    <li><span class="{{ $strong }}">Deleted permanently (cannot be undone):</span> a single inspection, damage report or drill attachment{{ $isAdmin ? ', and user accounts' : '' }}.</li>
                    <li>Every delete asks you to confirm first and says which of the two it is. A <span class="{{ $strong }}">View Trash</span> button appears on a list page when something of yours is in Trash.</li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection

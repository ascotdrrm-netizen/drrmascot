<?php

namespace App\Http\Controllers\Reporter;

use App\Http\Controllers\Controller;
use App\Support\Preview\Fixtures;
use App\Models\Advisory\DisasterAdvisory;
use App\Models\CampusStatus;
use App\Models\RollCall;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Phone screens backed by Reporter records, with local guest fixtures for UI previews. */
class AppPreviewController extends Controller
{
    protected function shared(): array
    {
        if ($this->live()) {
            $person = Auth::user()->loadMissing(['campus', 'school']);
            $advisories = $this->liveAdvisories();
            $campusStatus = CampusStatus::find($person->campus_id);
            $status = $campusStatus?->status ?: 'normal';
            $labels = ['normal' => 'Normal', 'advisory' => 'Advisory in effect',
                'suspended' => 'Classes suspended', 'evacuate' => 'Evacuate'];

            return [
                'reporter' => [
                    'name' => $person->full_name,
                    'initials' => collect(explode(' ', $person->full_name))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->join(''),
                    'role' => $person->reporter_kind ?: 'Reporter',
                    'id_number' => $person->id_number ?: '—',
                    'campus' => $person->campus?->campus_name ?: 'Campus',
                    'school' => $person->school?->school_name ?: '—',
                    'phone' => $person->contact_number ?: '—',
                    'phone_verified' => $person->phone_verified_at !== null,
                    'email' => $person->email,
                ],
                'statuses' => [[
                    'campus' => $person->campus?->campus_name ?: 'Campus',
                    'status' => $status,
                    'label' => $labels[$status] ?? 'Normal',
                    'detail' => $campusStatus?->detail ?: 'No emergency status in effect.',
                ]],
                'unread' => \App\Models\StaffNotification::where('personnel_id', $person->getKey())->where('campus_id', $person->campus_id)->whereNull('read_at')->count(),
            ];
        }

        return [
            'reporter' => Fixtures::reporter(),
            'statuses' => Fixtures::campusStatus(),
            'unread' => count(Fixtures::advisories()),
        ];
    }

    protected function live(): bool
    {
        return Auth::check() && Auth::user()->role === 'Reporter';
    }

    protected function liveAdvisories(): array
    {
        return DisasterAdvisory::live()->where(fn ($query) => $query->whereNull('campus_id')
            ->orWhere('campus_id', Auth::user()->campus_id))->latest('issued_at')->limit(20)->get()
            ->map(fn ($advisory) => [
                'id' => $advisory->advisory_id,
                'hazard' => $advisory->hazard_label,
                'level' => $advisory->status_label,
                'tone' => 'amber',
                'title' => $advisory->headline,
                'campus' => $advisory->campus_label,
                'issued' => $advisory->issued_at,
                'body' => $advisory->instructions,
            ])->all();
    }

    protected function liveReports(): array
    {
        return CitizenReport::with(['events', 'photos'])->where('reporter_id', Auth::id())->latest()->limit(100)->get()
            ->map(fn ($report) => $this->presentReport($report))->all();
    }

    protected function presentReport(CitizenReport $report): array
    {
        $category = collect(Fixtures::categories())->firstWhere('key', $report->category)
            ?? ['label' => 'Other', 'icon' => 'more_horiz'];

        return [
            'id' => $report->report_id,
            'category' => $category['label'],
            'icon' => $category['icon'],
            'status' => $report->status === 'New' ? 'Submitted' : $report->status,
            'place' => $report->place ?: 'GPS location',
            'when' => $report->created_at,
            'description' => $report->description,
            'injured' => $report->people_injured,
            'timeline' => $report->events->map(fn ($event) => [
                'status' => $event->status === 'New' ? 'Submitted' : $event->status,
                'note' => $event->note, 'at' => $event->created_at,
            ])->all(),
            'photos' => $report->photos->map(fn ($photo) => [
                'url' => \App\Support\Files::url($photo->path), 'caption' => $photo->caption,
            ])->all(),
            'proof_url' => \App\Support\Files::url($report->proof_path),
        ];
    }

    public function home(): View
    {
        return view('reporter.home', $this->shared() + [
            'advisories' => array_slice($this->live() ? $this->liveAdvisories() : Fixtures::advisories(), 0, 2),
            'categories' => array_slice(Fixtures::categories(), 0, 6),
            'reports' => $this->live() ? array_slice($this->liveReports(), 0, 3) : Fixtures::reports(),
            'activeRollCall' => $this->live() ? RollCall::where('campus_id', Auth::user()->campus_id)->whereNull('closed_at')
                ->whereHas('responses', fn ($q) => $q->where('reporter_id', Auth::id()))->first() : null,
        ]);
    }

    public function sos(): View
    {
        return view('reporter.sos', $this->shared());
    }

    public function sosStatus(\Illuminate\Http\Request $request): View
    {
        if ($this->live()) {
            $request->validate(['sos' => ['nullable', 'integer', 'min:1']]);
            $sos = SosEvent::where('reporter_id', Auth::id())->when($request->filled('sos'), fn ($q) => $q->whereKey($request->integer('sos')))->latest()->firstOrFail();
            $timeline = [['status' => 'Sent', 'note' => 'Your SOS reached the DRRM Office.', 'at' => $sos->created_at, 'done' => true]];
            foreach (['Acknowledged' => 'The DRRM Office acknowledged your SOS.', 'Responding' => 'Help is on the way.', 'Resolved' => 'The response was completed.'] as $status => $note) {
                $done = in_array($status, match ($sos->status) {
                    'Resolved' => ['Acknowledged', 'Responding', 'Resolved'],
                    'Responding' => ['Acknowledged', 'Responding'],
                    'Acknowledged' => ['Acknowledged'],
                    default => [],
                }, true);
                $timeline[] = ['status' => $status, 'note' => $note, 'at' => $done ? ($status === 'Acknowledged' ? $sos->acknowledged_at : ($status === 'Resolved' ? $sos->resolved_at : $sos->updated_at)) : null, 'done' => $done];
            }

            return view('reporter.sos-status', $this->shared() + ['sos' => [
                'id' => $sos->sos_id, 'type' => $sos->type ?: 'Emergency', 'status' => $sos->status, 'sent_at' => $sos->created_at,
                'location' => $sos->place ?: ($sos->latitude ? 'GPS coordinates shared' : 'Location not shared'),
                'accuracy' => $sos->accuracy_m ?: 'unknown', 'timeline' => $timeline,
            ]]);
        }

        return view('reporter.sos-status', $this->shared() + ['sos' => Fixtures::sos()]);
    }

    public function report(): View
    {
        return view('reporter.report', $this->shared() + ['categories' => Fixtures::categories()]);
    }

    public function reports(): View
    {
        return view('reporter.reports', $this->shared() + ['reports' => $this->live() ? $this->liveReports() : Fixtures::reports()]);
    }

    public function reportShow(int $id): View
    {
        $report = $this->live()
            ? $this->presentReport(CitizenReport::with(['events', 'photos'])
                ->where('reporter_id', Auth::id())->findOrFail($id))
            : collect(Fixtures::reports())->firstWhere('id', $id);
        abort_if($report === null, 404);

        return view('reporter.report-show', $this->shared() + ['report' => $report]);
    }

    public function alerts(): View
    {
        return view('reporter.alerts', $this->shared() + ['advisories' => $this->live() ? $this->liveAdvisories() : Fixtures::advisories(),
            'notifications' => $this->live() ? \App\Models\StaffNotification::where('personnel_id', Auth::id())
                ->where('campus_id', Auth::user()->campus_id)->latest()->limit(100)->get() : collect()]);
    }

    public function broadcast(\App\Models\Broadcast $broadcast): View
    {
        abort_unless($broadcast->published_at && (int) $broadcast->campus_id === (int) Auth::user()->campus_id
            && $broadcast->deliveries()->where('personnel_id', Auth::id())->where('channel', 'in_app')->exists(), 404);
        return view('reporter.broadcast', $this->shared() + ['broadcast' => $broadcast->load('campus')]);
    }

    public function map(): View
    {
        return view('reporter.map', $this->shared() + [
            'centres' => config('campus_map.centres'),
        ]);
    }

    public function me(): View
    {
        return view('reporter.me', $this->shared());
    }

    /** First run: what the app is, and installing it. */
    public function welcome(): View
    {
        return view('reporter.welcome', $this->shared());
    }

    public function register(): View
    {
        return view('reporter.register', $this->shared() + [
            'campuses' => \App\Models\Campus::orderBy('campus_name')->get(),
            'schools' => \App\Models\School::forDropdowns(),
        ]);
    }

    public function login(): View
    {
        return view('reporter.login', $this->shared());
    }

    /** A roll call from the DRRM Office: "I'm safe" or "I need help". */
    public function checkIn(?RollCall $rollCall = null): View
    {
        if ($this->live()) {
            $rollCall ??= RollCall::where('campus_id', Auth::user()->campus_id)->whereNull('closed_at')
                ->whereHas('responses', fn ($q) => $q->where('reporter_id', Auth::id()))->first();
            if (! $rollCall) return view('reporter.check-in', $this->shared() + ['rollCall' => null, 'savedResponse' => null]);
            abort_unless((int) $rollCall->campus_id === (int) Auth::user()->campus_id, 404);
            $saved = $rollCall->responses()->where('reporter_id', Auth::id())->firstOrFail();
            return view('reporter.check-in', $this->shared() + [
                'rollCall' => ['id' => $rollCall->getKey(), 'title' => $rollCall->title, 'instructions' => $rollCall->instructions,
                    'campus' => $rollCall->campus->campus_name, 'started' => $rollCall->started_at,
                    'by' => 'ASCOT DRRM Office', 'closed' => $rollCall->closed_at !== null],
                'savedResponse' => ['answer' => $saved->answer, 'note' => $saved->note],
            ]);
        }
        return view('reporter.check-in', $this->shared() + ['rollCall' => [
            'title' => 'Typhoon Pepito roll call',
            'campus' => 'Zabali Campus',
            'started' => \Illuminate\Support\Carbon::now()->subMinutes(12),
            'by' => 'ASCOT DRRM Office',
        ]]);
    }

    public function hotlines(): View
    {
        // This public page is stored for offline use, so its HTML must never
        // contain an account name, campus status, or unread count.
        return view('reporter.hotlines', ['groups' => \App\Support\Hotlines::groups(), 'unread' => 0]);
    }
}

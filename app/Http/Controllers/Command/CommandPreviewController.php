<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Support\Hotlines;
use App\Models\Campus;
use App\Models\CampusStatus;
use App\Models\Reporter\CitizenReport;
use App\Models\Reporter\SosEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Staff command screens backed by Reporter submissions and campus status.
 */
class CommandPreviewController extends Controller
{
    protected function prefix(): string
    {
        return Auth::user()->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
    }

    public function inbox(): View
    {
        $query = CitizenReport::with(['reporter', 'campus', 'photos'])->latest();
        if ($this->prefix() === 'coordinator') {
            $query->where('campus_id', Auth::user()->campus_id);
        }
        $categories = collect(config('report_categories'))->keyBy('key');
        $reports = $query->limit(100)->get()->map(function ($report) use ($categories) {
            $category = $categories->get($report->category, ['label' => 'Other', 'icon' => 'more_horiz']);

            return [
                'id' => $report->report_id, 'status' => $report->status, 'urgent' => $report->urgent,
                'category' => $category['label'], 'icon' => $category['icon'],
                'campus' => $report->campus?->campus_name ?: 'Campus', 'place' => $report->place ?: 'GPS location',
                'lat' => $report->latitude === null ? null : (float) $report->latitude,
                'lng' => $report->longitude === null ? null : (float) $report->longitude,
                'accuracy' => $report->accuracy_m, 'reporter' => $report->reporter?->full_name ?: 'Former reporter',
                'role' => $report->reporter?->reporter_kind ?: 'Reporter',
                'phone' => $report->reporter?->contact_number ?: '', 'when' => $report->created_at,
                'injured' => $report->people_injured, 'description' => $report->description,
                'caption' => $report->photos->first()?->caption, 'tags' => [],
                'photo_url' => \App\Support\Files::url($report->photos->first()?->path),
                'proof_url' => \App\Support\Files::url($report->proof_path),
            ];
        })->all();

        return view('command.inbox', ['reports' => $reports, 'routePrefix' => $this->prefix()]);
    }

    public function sos(): View
    {
        // The numbers to escalate to, from the hotline list the DRRMO keeps.
        $escalate = collect(Hotlines::groups())->flatMap(fn ($g) => $g['entries'])
            ->filter(fn ($e) => in_array($e['label'], ['Baler BFP', 'MDRRMO Baler', 'RHU Baler', 'Maria Aurora MDRRMO', 'Casiguran MDRRMO'], true) && ! empty($e['numbers']))
            ->map(fn ($e) => ['label' => $e['label'], 'number' => $e['numbers'][0]])->values();

        $query = SosEvent::with(['reporter', 'campus'])->where('status', '!=', 'Resolved')->oldest();
        if ($this->prefix() === 'coordinator') {
            $query->where('campus_id', Auth::user()->campus_id);
        }
        $alerts = $query->limit(100)->get()->map(fn ($sos) => [
            'id' => $sos->sos_id, 'type' => $sos->type, 'reporter' => $sos->reporter?->full_name ?: 'Former reporter',
            'role' => $sos->reporter?->reporter_kind ?: 'Reporter', 'phone' => $sos->reporter?->contact_number ?: '',
            'campus' => $sos->campus?->campus_name ?: 'Campus', 'place' => $sos->place ?: 'Location not shared',
            'lat' => $sos->latitude === null ? null : (float) $sos->latitude,
            'lng' => $sos->longitude === null ? null : (float) $sos->longitude,
            'accuracy' => $sos->accuracy_m, 'sent_at' => $sos->created_at,
            'acknowledged' => $sos->status !== 'New', 'status' => $sos->status,
        ])->all();

        return view('command.sos', ['alerts' => $alerts, 'escalate' => $escalate, 'routePrefix' => $this->prefix()]);
    }

    public function campusStatus(): View
    {
        $campuses = Campus::query()->orderBy('campus_name');
        if ($this->prefix() === 'coordinator') {
            $campuses->where('campus_id', Auth::user()->campus_id);
        }
        $stored = CampusStatus::whereIn('campus_id', $campuses->pluck('campus_id'))->get()->keyBy('campus_id');
        $statuses = $campuses->get()->map(fn ($campus) => [
            'campus_id' => $campus->campus_id,
            'campus' => $campus->campus_name,
            'status' => $stored->get($campus->campus_id)?->status ?: 'normal',
            'detail' => $stored->get($campus->campus_id)?->detail ?: 'No emergency status in effect.',
        ])->all();

        return view('command.campus-status', ['statuses' => $statuses, 'routePrefix' => $this->prefix()]);
    }
}

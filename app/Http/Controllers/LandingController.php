<?php

namespace App\Http\Controllers;

use App\Models\Advisory\DisasterAdvisory;
use App\Models\Campus;
use App\Models\CampusStatus;
use Illuminate\View\View;

class LandingController extends Controller
{
    /**
     * Public home page — the campus list for the footer and each campus's
     * status right now. The emergency hotlines come from config/hotlines.php
     * (read in the view); the Facebook page is a plain footer link, with no
     * embed. No personnel data is loaded for this page.
     */
    public function index(): View
    {
        $campuses = Campus::orderBy('campus_name')->get();

        // Advisories in effect (Active, not past valid-until): public warnings,
        // the same rule the dashboards use. An all-campus advisory (no
        // campus) counts for every campus.
        $live = DisasterAdvisory::live()->orderByDesc('issued_at')->get();

        $published = CampusStatus::whereIn('campus_id', $campuses->pluck('campus_id'))->get()->keyBy('campus_id');
        $campusStatus = $campuses->map(fn (Campus $campus) => [
            'campus' => $campus->campus_name,
            'status' => $published->get($campus->campus_id)?->status ?: 'normal',
            'detail' => $published->get($campus->campus_id)?->detail,
            'advisories' => $live->filter(fn ($a) => $a->campus_id === null || (int) $a->campus_id === (int) $campus->campus_id)->values(),
        ]);

        return view('landing', [
            'campuses' => $campuses,
            'campusStatus' => $campusStatus,
        ]);
    }
}

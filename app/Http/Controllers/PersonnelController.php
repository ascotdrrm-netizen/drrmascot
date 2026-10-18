<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Http\Controllers\Concerns\HandlesSchoolFilter;
use App\Models\Personnel;
use App\Models\School;
use App\Rules\SchoolOnCampus;
use App\Rules\ValidName;
use App\Support\FilterSummary;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PersonnelController extends Controller
{
    use RendersPdfAssets, HandlesSchoolFilter;

    public function index(Request $request): View
    {
        $query = Personnel::with(['campus', 'school']);
        $campuses = Campus::orderBy('campus_name')->get();

        // Unknown filter values are ignored rather than matched literally, so
        // the summary line can name exactly what the list is filtered by.
        $roles = ['Coordinator' => 'Coordinators', 'DRRMO Admin' => 'DRRMO Admins', 'Reporter' => 'Reporters'];
        $role = is_string($request->input('role')) && isset($roles[$request->input('role')]) ? $request->input('role') : null;
        $campus = ctype_digit((string) $request->input('campus_id')) ? $campuses->firstWhere('campus_id', (int) $request->input('campus_id')) : null;

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($campus) {
            $query->where('campus_id', $campus->campus_id);
        }

        // View filter only; a school must sit on the selected campus (if any).
        $schoolFilter = $this->validatedSchoolFilter($request, $campus?->campus_id);
        $this->applySchoolFilter($query, $schoolFilter);

        $personnel = $query->where('is_approved', true)->get()
            ->sortBy(fn ($person) => ($person->campus->campus_name ?? '') . '|' . $person->full_name)
            ->values();

        return view('personnel.index', [
            // Self-registered accounts waiting for approval, listed first (not filtered).
            'pending' => Personnel::with(['campus', 'school'])->where('is_approved', false)->orderBy('personnel_id')->get(),
            'personnel' => $personnel,
            'campuses' => $campuses,
            'selectedRole' => $role,
            'selectedCampusId' => $campus?->campus_id,
            'schools' => School::forDropdowns(),
            'selectedSchoolId' => $schoolFilter,
            // From the filters applied above, never from the form (see x-filter-bar).
            'filterSummary' => FilterSummary::make(
                ($role !== null ? $roles[$role] : 'all accounts') . FilterSummary::school($schoolFilter, 'in'),
                FilterSummary::who($campus, $schoolFilter),
            ),
            'filtersApplied' => $role !== null || $campus !== null || $schoolFilter !== null,
        ]);
    }

    public function downloadReport(): Response
    {
        $personnel = Personnel::with(['campus', 'school'])
            ->where('is_approved', true) // a pending registration is not yet personnel
            ->get()
            ->sortBy(fn ($person) => ($person->campus->campus_name ?? '') . '|' . $person->full_name)
            ->values();

        $groupedByCampus = $personnel->groupBy(fn ($person) => $person->campus->campus_name ?? 'Unassigned');

        $pdf = $this->loadPdfViewWithPageCount('personnel.report', [
            'groupedByCampus' => $groupedByCampus,
            'totalCount' => $personnel->count(),
        ]);

        $filename = sprintf('Personnel-Directory-%s.pdf', now()->format('Y-m-d'));

        return $pdf->download($filename);
    }

    /** Lets a self-registered account sign in. */
    public function pendingReporters(Request $request): View
    {
        return view('personnel.pending-reporters', [
            'pending' => Personnel::with(['school'])->where('role', 'Reporter')
                ->where('campus_id', $request->user()->campus_id)
                ->whereNotNull('email_verified_at')->whereNull('validated_by_coordinator_at')
                ->orderBy('personnel_id')->get(),
        ]);
    }

    /** Lets a self-registered account sign in. */
    public function validateReporter(Request $request, Personnel $personnel): RedirectResponse
    {
        abort_unless($personnel->role === 'Reporter'
            && (int) $personnel->campus_id === (int) $request->user()->campus_id, 404);
        abort_unless($personnel->hasVerifiedEmail() && $personnel->validated_by_coordinator_at === null, 422);

        $personnel->forceFill(['validated_by_coordinator_at' => now()])->save();
        $this->logAccountDecision($request, $personnel, 'reporter_validated', 'validated the reporter account of');

        return back()->with('status', "{$personnel->full_name}'s account is validated and awaiting Admin approval.");
    }

    /** Lets a self-registered account sign in. */
    public function approve(Request $request, Personnel $personnel): RedirectResponse
    {
        abort_if($personnel->is_approved, 404);

        if ($personnel->role === 'Reporter') {
            abort_unless($personnel->hasVerifiedEmail() && $personnel->validated_by_coordinator_at !== null, 422,
                'The Reporter must verify their email and be validated by their campus coordinator first.');
        }

        $personnel->forceFill(['is_approved' => true, 'approved_at' => now()])->save();
        $this->logAccountDecision($request, $personnel, 'account_approved', 'approved the account of');

        return redirect()->route('admin.personnel.index')
            ->with('status', "{$personnel->full_name}'s account is approved; they can sign in now.");
    }

    /** Turns down a self-registration: the account is removed (it never signed in, so nothing references it). */
    public function reject(Request $request, Personnel $personnel): RedirectResponse
    {
        abort_if($personnel->is_approved, 404);

        // Logged first, while the account still exists to be named.
        $this->logAccountDecision($request, $personnel, 'account_rejected', 'rejected the registration of');
        $personnel->delete();

        return redirect()->route('admin.personnel.index')
            ->with('status', "{$personnel->full_name}'s registration was rejected and removed.");
    }

    protected function logAccountDecision(Request $request, Personnel $personnel, string $action, string $verb): void
    {
        \App\Models\ActivityLog::create([
            'personnel_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'personnel',
            'subject_id' => $personnel->personnel_id,
            'description' => mb_substr(Auth::user()->full_name . " {$verb} {$personnel->full_name} ({$personnel->email}, " . ($personnel->campus->campus_name ?? 'no campus') . ')', 0, 255),
            'ip_address' => $request->ip(),
        ]);
    }

    public function edit(Personnel $personnel): View
    {
        return view('personnel.edit', [
            'personnel' => $personnel,
            'campuses' => Campus::orderBy('campus_name')->get(),
            'schools' => School::forDropdowns(),
        ]);
    }

    public function update(Request $request, Personnel $personnel): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150', new ValidName()],
            'role' => ['required', 'in:Coordinator,DRRMO Admin,Reporter'],
            'campus_id' => ['required', 'exists:campuses,campus_id'],
            'contact_number' => ['nullable', 'string', 'max:20', new \App\Rules\ValidPhone()],
            // Must be a school on the (possibly new) campus. When the campus
            // changes, the dropdown clears the school; a stale school from the
            // old campus posted anyway is rejected here, never saved.
            'school_id' => ['nullable', new SchoolOnCampus(ctype_digit((string) $request->input('campus_id')) ? (int) $request->input('campus_id') : 0)],
        ]);

        if ($personnel->role === 'Reporter' && $data['role'] !== 'Reporter') {
            abort_unless($personnel->is_approved && $personnel->hasVerifiedEmail()
                && $personnel->validated_by_coordinator_at !== null, 422,
                'Verify, validate and approve this Reporter before assigning a staff role.');
        }

        // Same safety property as destroy()'s "cannot delete the last remaining
        // Admin": reassigning the last Admin away from that role would leave the
        // system with zero Admins, so reject before saving anything.
        if ($personnel->role === 'DRRMO Admin' && $data['role'] !== 'DRRMO Admin') {
            $adminCount = Personnel::where('role', 'DRRMO Admin')->count();

            if ($adminCount <= 1) {
                return back()->withErrors([
                    'role' => 'Cannot change the role of the last remaining DRRMO Admin account.',
                ]);
            }
        }

        $personnel->update([
            'full_name' => $data['full_name'],
            'role' => $data['role'],
            'campus_id' => $data['campus_id'],
            'school_id' => $data['school_id'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
        ]);

        return redirect()
            ->route('admin.personnel.index')
            ->with('status', 'Account updated.');
    }

    public function destroy(Personnel $personnel): RedirectResponse
    {
        // Checked before the "own account" guard: with only one admin left, that
        // admin is the only session that could ever reach this action against
        // their own id, so this check must run first to be independently
        // reachable rather than permanently shadowed by the own-account guard.
        if ($personnel->role === 'DRRMO Admin') {
            $adminCount = Personnel::where('role', 'DRRMO Admin')->count();

            if ($adminCount <= 1) {
                return back()->withErrors([
                    'personnel' => 'Cannot delete the last remaining DRRMO Admin account.',
                ]);
            }
        }

        if (Auth::id() === $personnel->personnel_id) {
            return back()->withErrors([
                'personnel' => 'You cannot delete your own account.',
            ]);
        }

        $personnel->delete();

        return redirect()
            ->route('admin.personnel.index')
            ->with('status', 'Account permanently deleted.');
    }
}

<?php

namespace App\Http\Controllers\Hotline;

use App\Http\Controllers\Controller;
use App\Models\Hotline\Hotline;
use App\Models\Hotline\HotlineGroup;
use App\Support\Hotlines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Editing the emergency hotlines (thesis panel, Engineering): the DRRMO
 * Admin edits every group; a coordinator edits the group that serves their
 * campus. What is saved here is what the landing page, the phone app and
 * the error pages show (App\Support\Hotlines).
 */
class HotlineController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        // Every group is shown (coordinators call the province and other
        // towns too); Edit appears only on the ones $user may change.
        $groups = HotlineGroup::with('hotlines', 'campus')->orderBy('sort_order')->get();

        return view('hotlines.index', [
            'groups' => $groups,
            'editable' => $groups->filter(fn (HotlineGroup $group) => $group->editableBy($user))->pluck('hotline_group_id')->all(),
            'routePrefix' => $user->role === 'DRRMO Admin' ? 'admin' : 'coordinator',
        ]);
    }

    public function edit(HotlineGroup $group): View
    {
        $user = Auth::user();
        abort_unless($group->editableBy($user), 403);

        return view('hotlines.edit', [
            'group' => $group->load('hotlines'),
            'routePrefix' => $user->role === 'DRRMO Admin' ? 'admin' : 'coordinator',
        ]);
    }

    public function update(Request $request, HotlineGroup $group): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($group->editableBy($user), 403);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
            'entries' => ['array', 'max:30'],
            'entries.*.label' => ['nullable', 'string', 'max:100'],
            'entries.*.numbers' => ['nullable', 'string', 'max:200'],
            'entries.*.radio' => ['nullable', 'string', 'max:40'],
        ]);

        // Rows with a name; numbers typed one per line or comma-separated.
        $rows = collect($data['entries'] ?? [])
            ->filter(fn ($row) => filled($row['label'] ?? null))
            ->values()
            ->map(function ($row, $i) {
                $numbers = collect(preg_split('/[\r\n,;]+/', (string) ($row['numbers'] ?? '')))
                    ->map(fn ($n) => trim($n))->filter()->values()->all();
                foreach ($numbers as $number) {
                    if (! preg_match('/^\+?[0-9][0-9\s\-()]{5,18}$/', $number)) {
                        throw ValidationException::withMessages(["entries.$i.numbers" => "“{$number}” doesn’t look like a phone number."]);
                    }
                }

                return ['label' => trim($row['label']), 'numbers' => $numbers, 'radio' => filled($row['radio'] ?? null) ? trim($row['radio']) : null];
            });

        DB::transaction(function () use ($group, $rows, $data) {
            $group->update(['note' => $data['note'] ?? null]);
            $existing = $group->hotlines()->get()->values();

            foreach ($rows as $i => $row) {
                $attributes = [
                    'label' => $row['label'],
                    'numbers' => $row['radio'] !== null ? null : $row['numbers'],
                    'radio' => $row['radio'],
                    'sort_order' => $i,
                ];
                isset($existing[$i]) ? $existing[$i]->update($attributes) : $group->hotlines()->create($attributes);
            }

            $existing->slice($rows->count())->each(fn (Hotline $hotline) => $hotline->delete());
        });

        Hotlines::flush();

        return redirect()->route(($user->role === 'DRRMO Admin' ? 'admin' : 'coordinator') . '.hotlines.index')
            ->with('status', "Hotlines for {$group->title} saved. The home page and the phone app show them now.");
    }
}

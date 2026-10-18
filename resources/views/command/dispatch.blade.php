@extends('layouts.app')
@section('title', 'Dispatch - ASCOT DRRMS')
@section('heading', 'Dispatch')
@section('subheading', 'Assign available campus responders and record their arrival and completion.')
@section('content')
    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <x-card>
            <h2 class="card-title mb-4">Assign a responder</h2>
            <p class="form-help mb-4">Dispatching a new case also verifies the report or acknowledges the SOS. Call the responder to confirm the dispatch.</p>
            <form method="POST" action="{{ route('api.command.assignments.store') }}" data-ajax data-ajax-stay data-ajax-reset data-ajax-refresh="#dispatch-board,#dispatch-options" class="space-y-4">
                @csrf
                <div id="dispatch-options">
                <div><label for="target" class="form-label">Report or SOS</label><select id="target" name="target" required class="form-input">
                    <option value="">{{ $sosEvents->isEmpty() && $reports->isEmpty() ? 'No open reports or SOS right now' : 'Select a case' }}</option>
                    @if ($sosEvents->isNotEmpty())
                        <optgroup label="SOS">@foreach ($sosEvents as $sos)<option value="sos:{{ $sos->getKey() }}">SOS #{{ $sos->getKey() }} · {{ $sos->type ?: 'Emergency' }} · {{ $sos->place ?: 'Location not shared' }} · {{ $sos->status }}</option>@endforeach</optgroup>
                    @endif
                    @if ($reports->isNotEmpty())
                        <optgroup label="Reports">@foreach ($reports as $report)<option value="report:{{ $report->getKey() }}">Report #{{ $report->getKey() }} · {{ $report->category }} · {{ $report->place ?: 'GPS location' }} · {{ $report->status }}</option>@endforeach</optgroup>
                    @endif
                </select>
                @if ($sosEvents->isEmpty() && $reports->isEmpty())
                    <p class="form-help mt-1">No open reports or SOS right now. New ones from the Reporter app appear here.</p>
                @endif
                </div>
                <div><label for="responder_id" class="form-label">Responder</label><select id="responder_id" name="responder_id" required class="form-input">
                    <option value="">Select a responder</option>
                    @foreach ($responders->where('is_active', true) as $responder)<option value="{{ $responder->getKey() }}" @disabled(! $responder->is_on_duty || $responder->activeAssignment)>{{ $responder->full_name }} · {{ $responder->team }} · {{ $responder->campus->campus_name }}</option>@endforeach
                </select></div>
                </div>
                <div><label for="notes" class="form-label">Dispatch instructions (optional)</label><textarea id="notes" name="notes" maxlength="500" rows="2" class="form-input"></textarea></div>
                <button type="submit" class="btn btn-primary">Assign responder</button>
            </form>
        </x-card>
        <x-card>
            <h2 class="card-title mb-4">Add a campus responder</h2>
            <form method="POST" action="{{ route('api.command.responders.store') }}" data-ajax data-ajax-stay data-ajax-reset data-ajax-refresh="#dispatch-board,#dispatch-options" class="space-y-4">
                @csrf
                <div><label for="responder_name" class="form-label">Name</label><input id="responder_name" name="full_name" maxlength="150" required class="form-input"></div>
                <div><label for="team" class="form-label">Team / office</label><input id="team" name="team" maxlength="100" required class="form-input" placeholder="e.g. Clinic or campus security"></div>
                <div><label for="contact_number" class="form-label">Contact number</label><input id="contact_number" name="contact_number" maxlength="20" required class="form-input"></div>
                <div><label for="responder_campus" class="form-label">Campus</label><select id="responder_campus" name="campus_id" required class="form-input">@foreach ($campuses as $campus)<option value="{{ $campus->getKey() }}">{{ $campus->campus_name }}</option>@endforeach</select></div>
                <button type="submit" class="btn btn-secondary">Add responder</button>
            </form>
        </x-card>
    </div>
    <div id="dispatch-board" data-live-refresh>
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-stat-card label="Available now" :value="$responders->filter(fn ($r) => $r->is_active && $r->is_on_duty && ! $r->activeAssignment)->count()" />
            <x-stat-card label="Active assignments" :value="$responders->filter(fn ($r) => $r->activeAssignment !== null)->count()" />
            <x-stat-card label="Average dispatch-to-arrival" :value="$arrivalMinutes === null ? '—' : $arrivalMinutes . ' min'" :subtitle="$arrivalSample . ' recorded arrivals from dispatches in the last 30 days'" />
        </div>
        <h2 class="section-title mb-4">Responders</h2>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($responders as $responder)
                <article class="card">
                    <h3 class="font-semibold">{{ $responder->full_name }}</h3>
                    <p class="text-sm text-gray-600">{{ $responder->team }} · {{ $responder->campus->campus_name }}</p>
                    <p class="mt-2 text-sm font-medium">{{ ! $responder->is_active ? 'Inactive' : ($responder->activeAssignment ? 'Assigned' : ($responder->is_on_duty ? 'Available' : 'Off duty')) }}</p>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $responder->contact_number) }}" class="link mt-2 inline-block">{{ $responder->contact_number }}</a>
                    <form method="POST" action="{{ route('api.command.responders.update', $responder) }}" data-ajax data-ajax-refresh="#dispatch-board,#dispatch-options" class="mt-3">
                        @csrf @method('PATCH')<input type="hidden" name="is_on_duty" value="{{ $responder->is_on_duty ? 0 : 1 }}">
                        <button type="submit" class="btn btn-secondary btn-sm">{{ $responder->is_on_duty ? 'Mark off duty' : 'Mark on duty' }}</button>
                    </form>
                </article>
            @empty <p class="card text-sm text-gray-600">No responders registered yet.</p> @endforelse
        </div>
        <h2 class="section-title mb-2">Assignments</h2>
        <p class="mb-4 text-sm text-gray-600">Completing an assignment releases the responder. Close the report or SOS separately after all response work is done.</p>
        <div class="space-y-4">
            @forelse ($assignments as $assignment)
                <article class="card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><h3 class="font-semibold">{{ $assignment->responder->full_name }} · {{ $assignment->report_id ? 'Report #' . $assignment->report_id : 'SOS #' . $assignment->sos_id }}</h3>
                            <p class="text-sm text-gray-600">{{ $assignment->report?->place ?: ($assignment->sos?->place ?: 'Location not shared') }} · assigned {{ $assignment->assigned_at->diffForHumans() }}</p>
                            @if ($assignment->notes)<p class="mt-2 text-sm">{{ $assignment->notes }}</p>@endif
                        </div>
                        <span class="chip border-gray-200">{{ $assignment->status }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (match ($assignment->status) { 'Assigned' => ['On scene', 'Cancelled'], 'On scene' => ['Completed', 'Cancelled'], default => [] } as $status)
                            <button type="button" data-ajax-action="{{ route('api.command.assignments.update', $assignment) }}" data-method="patch" data-payload='@json(["status" => $status])' data-ajax-refresh="#dispatch-board,#dispatch-options" class="btn btn-secondary btn-sm">{{ $status === 'Cancelled' ? 'Cancel assignment' : 'Mark ' . strtolower($status) }}</button>
                        @endforeach
                    </div>
                </article>
            @empty <p class="card text-sm text-gray-600">No dispatch assignments yet.</p> @endforelse
        </div>
    </div>
@endsection

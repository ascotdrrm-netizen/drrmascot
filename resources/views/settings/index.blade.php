@extends('layouts.app')

@section('title', 'Settings - ASCOT DRRMS')
@section('heading', 'Settings')
@section('subheading', 'Schools and courses for each campus, and who signs the PDF reports.')

@section('content')
    {{-- Report signatory (thesis panel, Engineering). --}}
    <section class="page-section">
        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.settings.signatory') }}" class="card max-w-3xl space-y-4" data-signatory-form>
            @csrf
            @method('PUT')
            <div>
                <h2 class="card-title">Report signatory: “Noted by”</h2>
                <p class="caption leading-6">Printed under “Noted by” on every PDF report. It is never the same person as “Prepared by”: when the person preparing the report is this official, the name is left blank to sign by hand.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="noted_by_name" class="form-label">Name (optional)</label>
                    <input type="text" id="noted_by_name" name="noted_by_name" maxlength="120" class="form-input"
                           value="{{ old('noted_by_name', $notedByName) }}" placeholder="Leave blank to sign by hand">
                </div>
                <div>
                    <label for="noted_by_position" class="form-label">Position</label>
                    <input type="text" id="noted_by_position" name="noted_by_position" maxlength="120" required class="form-input"
                           value="{{ old('noted_by_position', $notedByPosition) }}">
                </div>
            </div>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save signatory</button></div>
        </form>
    </section>

    {{-- Schools and courses per campus (thesis panel, Accountancy). --}}
    <section class="page-section">
        <div class="section-header">
            <div>
                <h2 class="section-title">Schools and courses</h2>
                <p class="caption leading-6">What people choose when they register, and what records are attributed to. Renaming keeps every record under the new name.</p>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3" data-school-settings>
            @foreach ($campuses as $campus)
                <div class="card space-y-3" data-campus-schools="{{ $campus->campus_id }}">
                    <h3 class="card-title">{{ $campus->campus_name }}</h3>
                    @foreach ($campus->schools as $school)
                        <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.settings.schools.update', $school) }}" class="flex gap-2">
                            @csrf
                            @method('PUT')
                            <label for="school-{{ $school->school_id }}" class="sr-only">School name</label>
                            <input type="text" id="school-{{ $school->school_id }}" name="school_name" maxlength="100" required class="form-input form-input-sm"
                                   value="{{ $school->school_name }}">
                            <button type="submit" class="btn btn-secondary btn-sm shrink-0">Rename</button>
                        </form>
                    @endforeach
                    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.settings.schools.store') }}" class="flex gap-2 border-t border-gray-100 pt-3">
                        @csrf
                        <input type="hidden" name="campus_id" value="{{ $campus->campus_id }}">
                        <label for="new-school-{{ $campus->campus_id }}" class="sr-only">New school or course</label>
                        <input type="text" id="new-school-{{ $campus->campus_id }}" name="school_name" maxlength="100" required class="form-input form-input-sm" placeholder="e.g. School of Accountancy">
                        <button type="submit" class="btn btn-primary btn-sm shrink-0"><x-icon name="add" class="h-4 w-4" /> Add</button>
                    </form>
                </div>
            @endforeach
        </div>
    </section>
@endsection

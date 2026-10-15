@extends('reports.pdf.document')

@php
    $docTitle = 'Personnel Directory';
    $docSubtitle = $totalCount . ' personnel record(s) as of ' . now()->format('F j, Y');
    $docCampusLabel = 'All Campuses';
    $docReferenceNote = 'Not assigned — directory listing, not a single record';
@endphp

@section('content')
    <table class="data">
        <thead>
            <tr>
                <th style="width: 24%;">Name</th>
                <th style="width: 26%;">Email</th>
                <th style="width: 14%;">Role</th>
                <th style="width: 22%;">School</th>
                <th>Contact number</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groupedByCampus as $campusName => $people)
                <tr class="group">
                    <td colspan="5">{{ $campusName }} ({{ $people->count() }})</td>
                </tr>
                @foreach ($people as $person)
                    <tr>
                        <td>{{ $person->full_name }}</td>
                        <td class="wrap">{{ $person->email }}</td>
                        <td>{{ $person->role }}</td>
                        <td>{{ $person->school->school_name ?? '—' }}</td>
                        <td class="nowrap"><x-pdf.value :value="$person->contact_number" empty="—" /></td>
                    </tr>
                @endforeach
            @empty
                <tr><td class="none" colspan="5">No personnel records found.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

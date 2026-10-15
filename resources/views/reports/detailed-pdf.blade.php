@extends('reports.pdf.document')

@php
    $docTitle = $title;
    $docSubtitle = $period['label'];
    $docCampusLabel = $campusName;
    $docSchoolLabel = $schoolName ?? 'All schools';
    $docReferenceNote = 'Not assigned — period reports summarise many records';
    $docFiguresNote = true;
@endphp

@section('styles')
    /* Drill Tracker consolidated reports: one block per drill, never split across pages. */
    .drill-entry { page-break-inside: avoid; border-top: 1px solid #d1d5db; padding-top: 6px; margin-top: 10px; }
    .drill-entry h3 { font-size: 11px; margin: 0 0 4px; }
    table.thumbs { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.thumbs td { width: 50%; text-align: center; vertical-align: top; padding: 0 4px 4px; }
@endsection

@section('content')
    @foreach ($modules as $i => $module)
        <div @if ($i > 0) style="page-break-before: always;" @endif>
            @include('reports.pdf-partials._detailed-' . $module, ['moduleData' => $moduleData[$module]])
        </div>
    @endforeach
@endsection

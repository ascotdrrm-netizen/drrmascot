<?php

namespace App\Http\Controllers\Drill;

use App\Models\Drill\DrillAfterActivityReport;

/**
 * A drill's After Activity Report (ASCOT's term). Field set PROVISIONAL —
 * agreed with the client; change rules() and the form together.
 */
class DrillAfterActivityReportController extends DrillSubmissionController
{
    protected function modelClass(): string
    {
        return DrillAfterActivityReport::class;
    }

    protected function relation(): string
    {
        return 'afterActivityReport';
    }

    protected function label(): string
    {
        return 'After Activity Report';
    }

    protected function routeKey(): string
    {
        return 'after-activity';
    }

    protected function formView(): string
    {
        return 'drill.after-activity';
    }

    protected function pdfView(): string
    {
        return 'reports.records.drill-after-activity';
    }

    protected function logAction(): string
    {
        return 'drill_after_activity_submitted';
    }

    protected function rules(): array
    {
        $count = ['nullable', 'integer', 'min:0', 'max:100000'];

        return [
            'activity_title' => ['required', 'string', 'max:200'],
            'date_conducted' => ['required', 'date', 'before_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'objectives' => ['nullable', 'string', 'max:3000'],
            'activities_conducted' => ['required', 'string', 'max:5000'],
            'participating_offices' => ['nullable', 'string', 'max:2000'],
            'participants_students' => $count,
            'participants_employees' => $count,
            'participants_others' => $count,
            'response_time_minutes' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'results' => ['nullable', 'string', 'max:5000'],
            'problems_encountered' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

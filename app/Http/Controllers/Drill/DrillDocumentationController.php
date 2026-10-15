<?php

namespace App\Http\Controllers\Drill;

use App\Models\Drill\DrillDocumentation;

/** A drill's documentation: what happened, after it was held. */
class DrillDocumentationController extends DrillSubmissionController
{
    protected function modelClass(): string
    {
        return DrillDocumentation::class;
    }

    protected function relation(): string
    {
        return 'documentation';
    }

    protected function label(): string
    {
        return 'Documentation';
    }

    protected function routeKey(): string
    {
        return 'documentation';
    }

    protected function formView(): string
    {
        return 'drill.documentation';
    }

    protected function pdfView(): string
    {
        return 'reports.records.drill-documentation';
    }

    protected function logAction(): string
    {
        return 'drill_documented';
    }

    protected function rules(): array
    {
        return [
            'date_conducted' => ['required', 'date', 'before_or_equal:today'],
            'time_conducted' => ['nullable', 'date_format:H:i'],
            'narrative' => ['required', 'string', 'max:5000'],
            'participant_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

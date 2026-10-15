<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates the period + campus filters for a single module's period
 * report (Facility Hazard Auditor, Incident Reporting, the Drill Tracker's
 * schedule / documentation / After Activity Reports, SMS & Broadcast
 * Alert), downloaded from that module's own
 * "Generate Report" modal. Which module is being requested comes from
 * the {module} route segment, not the query string, so it's checked
 * directly in ModuleReportController rather than here.
 */
class ModuleReportRequest extends FormRequest
{
    use ValidatesReportPeriod;

    public function authorize(): bool
    {
        return Auth::check();
    }

    protected function prepareForValidation(): void
    {
        $this->periodDefaultsForValidation();
    }

    public function rules(): array
    {
        return $this->periodRules();
    }
}

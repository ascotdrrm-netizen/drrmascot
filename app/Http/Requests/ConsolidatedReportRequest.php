<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ConsolidatedReportRequest extends FormRequest
{
    use ValidatesReportPeriod;

    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * First visit to the report page has no query string at all — default
     * to the current quarter rather than failing validation on a missing
     * period_type. Once period_type is present (the filter form was
     * actually submitted, even with only that one field), no defaults are
     * injected and every other field is validated for real.
     */
    protected function prepareForValidation(): void
    {
        $this->periodDefaultsForValidation();
    }

    public function rules(): array
    {
        return $this->periodRules();
    }
}

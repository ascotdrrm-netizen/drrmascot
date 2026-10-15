<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class IncidentsExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function __construct(protected Collection $incidents)
    {
    }

    public function collection(): Collection
    {
        return $this->incidents;
    }

    public function headings(): array
    {
        return [
            'Incident ID',
            'Hazard Type',
            'Date Occurred',
            'Status',
            'Campus',
            'Description',
            'Buildings Affected',
            'Total Casualties',
            'Total Estimated Cost',
        ];
    }

    public function map($incident): array
    {
        return [
            $incident->incident_id,
            $incident->hazard_type,
            $incident->date_occurred->format('Y-m-d'),
            $incident->status,
            $incident->campus->campus_name ?? '—',
            $incident->description ?? '—',
            $incident->damageReports->count(),
            $incident->damageReports->sum('casualty_count'),
            $incident->damageReports->sum('est_cost'),
        ];
    }
}

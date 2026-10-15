<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class ActivityLogExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function __construct(protected Builder $query)
    {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Timestamp', 'User', 'Action', 'Description', 'IP Address'];
    }

    public function map($log): array
    {
        return [
            optional($log->created_at)->format('Y-m-d H:i:s'),
            $log->actor_label,
            $log->action,
            $log->description,
            $log->ip_address ?? '—',
        ];
    }
}

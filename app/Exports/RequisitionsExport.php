<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class RequisitionsExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function __construct(protected Collection $requisitions)
    {
    }

    public function collection(): Collection
    {
        return $this->requisitions;
    }

    public function headings(): array
    {
        return [
            'Requisition ID',
            'Date Requested',
            'Status',
            'Campus',
            'Submitted By',
            'Items',
            'Has Letter',
        ];
    }

    public function map($requisition): array
    {
        return [
            $requisition->requisition_id,
            $requisition->date_requested->format('Y-m-d'),
            $requisition->status,
            $requisition->campus->campus_name ?? '—',
            $requisition->submittedBy->full_name ?? '—',
            $this->itemsSummary($requisition),
            $requisition->hasLetter() ? 'Yes' : 'No',
        ];
    }

    protected function itemsSummary($requisition): string
    {
        return $requisition->items
            ->map(fn ($item) => "{$item->itemDisplayName()} x{$item->quantity_requested}")
            ->implode(', ');
    }
}

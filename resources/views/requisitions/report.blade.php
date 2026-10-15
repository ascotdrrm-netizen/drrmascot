<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Requisition Slip - REQ-{{ $requisition->requisition_id }}</title>
    <style>
        @include('reports._pdf-style')
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Requisition Slip</div>

    <table class="details-table">
        <tr>
            <td class="label">Requisition ID</td>
            <td class="value">REQ-{{ $requisition->requisition_id }}</td>
        </tr>
        <tr>
            <td class="label">Campus</td>
            <td class="value">{{ $requisition->campus->campus_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Date requested</td>
            <td class="value">{{ $requisition->date_requested->format('F d, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td class="value">{{ $requisition->status }}</td>
        </tr>
        <tr>
            <td class="label">Submitted by</td>
            <td class="value">{{ $requisition->submittedBy->full_name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Supporting letter attached</td>
            <td class="value">{{ $requisition->hasLetter() ? 'Yes' : 'No' }}</td>
        </tr>
    </table>

    <div class="section-heading">Requested Items</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Source</th>
                <th>Quantity requested</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requisition->items as $item)
                <tr>
                    <td>{{ $item->itemDisplayName() }}</td>
                    <td>{{ $item->isCustomItem() ? 'Not in inventory catalog' : 'Catalog' }}</td>
                    <td>{{ $item->quantity_requested }}</td>
                </tr>
            @empty
                <tr>
                    <td class="no-data" colspan="3">No items recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($statusTimeline->isNotEmpty())
        <div class="section-heading">Status Timeline</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Actor</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statusTimeline as $entry)
                    <tr>
                        <td>{{ $entry->created_at->format('M d, Y g:i A') }}</td>
                        <td>{{ $entry->actor->full_name ?? '—' }}</td>
                        <td>{{ $entry->description }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signature-block">
        <tr>
            <td>
                <div class="signature-line">Requested by</div>
                <div class="signature-role">Coordinator, {{ $requisition->campus->campus_name ?? '—' }}</div>
                <div class="signature-role">Date: ___________________</div>
            </td>
            <td>
                <div class="signature-line">Approved by</div>
                <div class="signature-role">DRRMO Admin</div>
                <div class="signature-role">Date approved: ___________________</div>
            </td>
        </tr>
    </table>

    @include('reports._pdf-footer')
</body>
</html>

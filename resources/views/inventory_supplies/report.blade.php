<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Stock Report</title>
    <style>
        @include('reports._pdf-style')
    </style>
</head>
<body>
    @include('reports._pdf-header')

    <div class="title">Inventory Stock Report</div>

    <div class="summary-line">
        Generated as of {{ now()->format('F d, Y g:i A') }}
        &mdash; {{ $totalCount }} item(s) total,
        {{ $lowStockCount }} item(s) low on stock
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Quantity available</th>
                <th>Reorder level</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($supplies as $supply)
                <tr>
                    <td>{{ $supply->item_name }}</td>
                    <td>{{ $supply->category }}</td>
                    <td>{{ $supply->quantity_available }}</td>
                    <td>{{ $supply->reorder_level }}</td>
                    <td class="{{ $supply->isBelowReorderLevel() ? 'status-low' : 'status-ok' }}">
                        {{ $supply->isBelowReorderLevel() ? 'Low Stock' : 'OK' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="no-data" colspan="5">No inventory items recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="signature-block">
        <tr>
            <td>
                <div class="signature-line">Inventory checked by</div>
                <div class="signature-role">Date: ___________________</div>
            </td>
            <td>
                <div class="signature-line">Verified by</div>
                <div class="signature-role">Date: ___________________</div>
            </td>
        </tr>
    </table>

    @include('reports._pdf-footer')
</body>
</html>

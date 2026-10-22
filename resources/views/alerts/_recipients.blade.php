{{--
    Who an alert goes (or went) to: name, role, number. $recipients: Personnel
    (before sending) or AlertRecipient rows (saved with the alert, as they
    were then). $withStatus: show whether each text went through.
--}}
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th class="table-th">Name</th>
                <th class="table-th">Role</th>
                <th class="table-th">Contact number</th>
                @if ($withStatus)<th class="table-th">Status</th>@endif
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($recipients as $recipient)
                <tr>
                    <td class="table-td text-gray-900">{{ $recipient->full_name }}</td>
                    <td class="table-td text-gray-600">{{ $recipient->role }}</td>
                    <td class="table-td whitespace-nowrap text-gray-600">{{ $recipient->contact_number }}</td>
                    @if ($withStatus)
                        <td class="table-td">
                            <x-badge :value="$recipient->status" :map="[
                                'Sent' => 'bg-green-100 text-green-700',
                                'Failed' => 'bg-red-100 text-red-700',
                                'Skipped' => 'bg-gray-100 text-gray-700',
                            ]" />
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

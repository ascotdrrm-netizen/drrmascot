@extends('layouts.app')

@section('title', 'Send Alert - ASCOT DRRMS')
@section('heading', 'Send Alert')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.incidents.show', $incident) }}">Back to incident</x-back-link>
@endsection

@section('content')
    <x-card class="max-w-3xl">
        <p class="mb-6 text-sm text-gray-600">
            Incident: <span class="font-medium text-gray-900">{{ $incident->hazard_type }} — {{ $incident->campus->campus_name ?? 'Unknown campus' }}</span><br>
            This will be sent to <span class="font-medium text-gray-900">{{ $recipientCount }} personnel</span> at {{ $incident->campus->campus_name ?? 'this campus' }}
            with a contact number on file.
        </p>

        @if (config('services.sms.driver') === 'log')
            <p class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" data-sms-log-mode>
                <span class="font-semibold">SMS is in test mode on this server.</span>
                Messages are written to the system's log file, not sent to anyone's phone, although Alert History will still list them as Sent.
                Real sending starts once the system administrator connects an SMS gateway.
            </p>
        @endif

        @if ($recipientCount === 0)
            <p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                No personnel with a contact number on file for this campus — an alert cannot be sent.
                <span class="block font-normal">Each person can add their number under My Profile.</span>
            </p>
        @else
            <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route('admin.incidents.alerts.store', $incident) }}" class="space-y-4"
                  data-confirm-form="This sends the SMS to {{ $recipientCount }} people now. A sent message cannot be recalled." data-confirm-title="Send alert?" data-confirm-button="Send now" data-confirm-focus="cancel">
                @csrf

                <div>
                    <label for="message" class="form-label">Message</label>
                    <textarea id="message" name="message" maxlength="300" required rows="4" aria-describedby="message-help"
                              class="form-input">{{ old('message') }}</textarea>
                    <p id="message-help" class="form-help">Up to 300 characters. Say what happened, where, and what people should do.</p>
                </div>

                <x-form-actions>
                    <button type="submit" class="btn btn-primary">
                    Send Alert
                </button>
                </x-form-actions>
            </form>
        @endif
    </x-card>
@endsection

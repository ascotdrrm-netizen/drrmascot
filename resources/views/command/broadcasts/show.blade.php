@extends('layouts.app')
@section('title', 'Broadcast - ASCOT DRRMS')
@section('heading', 'Broadcast')
@section('breadcrumb')<x-back-link href="{{ route($routePrefix.'.broadcasts.index') }}">All broadcasts</x-back-link>@endsection
@section('content')
<div class="grid gap-6 lg:grid-cols-2">
    <x-card>
        <p class="text-sm text-gray-600">{{ $broadcast->campus->campus_name }} · {{ $broadcast->status }}</p>
        @if(!$broadcast->published_at)
            <form method="POST" action="{{ route($routePrefix.'.broadcasts.update', $broadcast) }}" class="mt-4 space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="campus_id" value="{{ $broadcast->campus_id }}">
                @include('command.broadcasts._fields')
                <button type="submit" class="btn btn-secondary">Save changes and refresh preview</button>
            </form>
        @else
            <h2 class="mt-3 text-lg font-semibold">{{ $broadcast->title }}</h2>
            <p class="mt-3 whitespace-pre-wrap text-sm leading-6">{{ $broadcast->body }}</p>
            @if($broadcast->send_sms)<h3 class="mt-4 font-semibold">SMS message</h3><p class="mt-2 whitespace-pre-wrap text-sm">{{ $broadcast->sms_text }}</p>@endif
            <p class="mt-4 text-sm text-gray-500">Published {{ $broadcast->published_at->format('M j, Y g:i A') }}</p>
        @endif
    </x-card>
    <x-card>
        @if($audience)
            <h2 class="card-title">Saved draft preview</h2>
            <p class="mt-2 text-sm text-gray-600">Publishing uses the saved message shown here. Save your edits before publishing.</p>
            <h3 class="mt-4 font-semibold">{{ $broadcast->title }}</h3><p class="mt-2 whitespace-pre-wrap text-sm">{{ $broadcast->body }}</p>
            @if($broadcast->send_sms)<p class="mt-3 whitespace-pre-wrap text-sm">SMS: {{ $broadcast->sms_text }}</p>@endif
            <dl class="mt-4 space-y-2 text-sm">
                <div>In-app notices: <strong>{{ $audience['counts']['in_app'] }}</strong></div>
                <div>Email addresses: <strong>{{ $audience['counts']['email'] }}</strong></div>
                <div>Mobile numbers: <strong>{{ $audience['counts']['sms'] }}</strong></div>
                @if($broadcast->send_sms)<div>Estimated SMS segments: <strong>{{ $audience['sms_units'] }}</strong> ({{ $audience['segments'] }} per recipient)</div>
                <div>Estimated SMS cost: <strong>{{ $audience['estimated_cost'] === null ? 'Rate not configured' : 'PHP '.number_format($audience['estimated_cost'], 2) }}</strong></div>@endif
            </dl>
            <p class="mt-3 text-xs text-gray-500">Email and SMS totals use verified contacts and exclude duplicate destinations. SMS pricing depends on the configured provider rate.</p>
            @if($broadcast->send_sms && config('services.sms.driver') !== 'semaphore')<p class="mt-3 text-sm text-amber-700">SMS uses the local log driver. Texts will be simulated.</p>@endif
            @if($broadcast->send_email && in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array'], true))<p class="mt-3 text-sm text-amber-700">Email uses a local driver. Emails will be simulated.</p>@endif
            <form method="POST" action="{{ route($routePrefix.'.broadcasts.publish', $broadcast) }}" class="mt-5">@csrf
                <input type="hidden" name="audience_fingerprint" value="{{ $audience['fingerprint'] }}">
                @error('audience_fingerprint')<p class="mb-3 text-sm text-red-700">{{ $message }}</p>@enderror
                <button type="submit" class="btn btn-primary" @disabled(!$audience['counts']['in_app'])>Publish saved message to this campus</button>
            </form>
        @else
            <div id="broadcast-history" data-live-refresh>
                <h2 class="card-title">Delivery history</h2>
                <p class="mt-2 text-sm text-gray-600">{{ $broadcast->status }}. Accepted means the provider accepted the request; handset delivery is not confirmed. Simulated uses a local mail/SMS driver.</p>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="py-2">Channel</th><th>Status</th><th>Count</th></tr></thead><tbody>@foreach($history as $row)<tr class="border-t border-gray-100"><td class="py-2">{{ $row->channel === 'in_app' ? 'In-app' : strtoupper($row->channel) }}</td><td>{{ $row->status }}</td><td>{{ $row->total }}</td></tr>@endforeach</tbody></table></div>
                @if($history->where('status', 'Failed')->sum('total'))<form method="POST" action="{{ route($routePrefix.'.broadcasts.retry', $broadcast) }}" class="mt-4">@csrf<button type="submit" class="btn btn-secondary">Retry rejected deliveries</button></form>@endif
                @foreach($problems as $problem)<p class="mt-3 text-sm text-gray-600">{{ strtoupper($problem->channel) }} · {{ $problem->destination }} · {{ $problem->status }}: {{ $problem->error }}</p>@endforeach
                <p class="mt-4 text-xs text-gray-500">Pending requests require the queue worker. Unconfirmed requests are not retried automatically; check the provider history before creating another message.</p>
            </div>
        @endif
    </x-card>
</div>
@endsection

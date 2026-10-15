{{--
    Admin dashboard: shown only when automatic snapshots are configured on this
    server AND no verified database dump (or file copy) is newer than
    config('backup.stale_after_hours'). Silent between normal 3-hourly runs.
    $backupSummary: App\Support\Backup\Snapshot::summary()
--}}
@if ($backupSummary && $backupSummary['configured'] && $backupSummary['alarm'])
    @php
        $status = $backupSummary['status'];
        $newest = $backupSummary['newest'];
        $filesAt = $backupSummary['files_at'];
        $failed = ($status['last_outcome'] ?? null) === 'failed' && ! empty($status['last_attempt_at']);
    @endphp
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert" data-backup-banner>
        <p class="font-semibold">
            {{ $newest ? 'Database backup is out of date' : 'No verified database backup yet' }}
        </p>
        <p class="mt-1">
            @if ($newest)
                Last verified copy: {{ $newest->format('M j, g:i A') }} ({{ $newest->diffForHumans() }}).
            @else
                No automatic copy of the database has been verified on this PC yet.
            @endif
            @if ($newest && (! $filesAt || $filesAt->lt(now()->subHours(config('backup.stale_after_hours')))))
                Uploaded files last copied: {{ $filesAt ? $filesAt->format('M j, g:i A') : 'never' }}.
            @endif
        </p>
        @if ($failed)
            <p class="mt-1">
                Last attempt ({{ \Carbon\CarbonImmutable::parse($status['last_attempt_at'])->setTimezone(config('app.timezone'))->format('M j, g:i A') }}) failed: {{ $status['last_error'] }}
            </p>
        @endif
        <p class="mt-1 text-amber-800">
            Copies are taken every 3 hours while this PC is signed in and online.
            <a href="{{ route('admin.backup.index') }}" class="font-medium underline">Backup &amp; Restore</a> lists them.
        </p>
    </div>
@endif

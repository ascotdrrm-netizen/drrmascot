@extends('layouts.app')

@section('title', 'Clear All Records - ASCOT DRRMS')
@section('heading', 'Clear All Records')
@section('subheading', 'Deletes every operational record and its uploaded files. Accounts and campus setup are kept.')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.backup.index') }}">Back to Backup &amp; Restore</x-back-link>
@endsection

@php
    use App\Support\RecordsClear;
    $nothingToClear = $inventory['total'] === 0 && $inventory['file_count'] === 0;
    $cleared = session('cleared');
    $blockedReason = match (true) {
        ! $dataPrivacyPasswordConfigured => 'The Data Privacy Password is not configured on this server. Contact your system administrator.',
        ! $zipSupported => "This server's PHP can't write password-protected ZIP backups, so the backup taken first can't be made — and nothing is cleared without one.",
        $nothingToClear => 'There is nothing to clear: every table below is already empty.',
        default => null,
    };
@endphp

@section('content')
    <div class="max-w-3xl space-y-6">
        @if ($cleared)
            <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900" role="status" data-clear-done>
                <p class="font-semibold">Records cleared: {{ $cleared['records'] }} {{ \Illuminate\Support\Str::plural('record', $cleared['records']) }} and {{ $cleared['files'] }} {{ \Illuminate\Support\Str::plural('file', $cleared['files']) }} deleted.</p>
                <p class="mt-2">The copy taken first is the stored backup
                    <span class="font-mono text-xs font-semibold">{{ $cleared['backup'] }}</span>
                    (listed under Stored Backups on <a href="{{ route('admin.backup.index') }}" class="underline">Backup &amp; Restore</a>, never removed automatically).</p>
                <p class="mt-1">Full details — every table, the Trash counts and every file deleted — are in
                    <span class="font-mono text-xs">{{ $cleared['manifest'] }}</span>.</p>
                @if ($cleared['folder'])
                    <p class="mt-1">A copy of the backup and of the deleted files is also in <span class="font-mono text-xs">{{ $cleared['folder'] }}</span>.</p>
                @endif
                @if (! empty($cleared['not_deleted']))
                    <p class="mt-2 font-medium text-red-800">{{ count($cleared['not_deleted']) }} {{ \Illuminate\Support\Str::plural('file', count($cleared['not_deleted'])) }} could not be deleted; they are listed in the details file.</p>
                @endif
            </div>
        @endif

        @error('clear')
            <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm font-medium text-red-800" role="alert" data-clear-error>{{ $message }}</div>
        @enderror

        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">This permanently deletes the records listed below, including everything in Trash, and the uploaded files behind them.</p>
            <p class="mt-1">It cannot be undone inside the system. The way back is restoring the copy taken immediately before the records are cleared.</p>
        </div>

        <x-card data-clear-inventory>
            <h2 class="card-title">Will be deleted</h2>
            <p class="caption mb-4">Counted from the database just now ({{ now()->format('M j, g:i A') }}).</p>

            <p class="mb-4 text-sm font-medium text-gray-900" data-clear-summary>{{ RecordsClear::summary($inventory) }}.</p>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="table-th">Records</th>
                            <th class="table-th text-right">Total</th>
                            <th class="table-th text-right">In Trash</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['incidents', 'damage_reports', 'alerts', 'buildings', 'hazard_inspections', 'drills', 'drill_documentations', 'drill_after_activity_reports', 'drill_attachments'] as $table)
                            @php $row = $inventory['tables'][$table]; @endphp
                            <tr>
                                <td class="table-td">{{ ucfirst(RecordsClear::TABLES[$table][1]) }}</td>
                                <td class="table-td text-right font-medium">{{ $row['count'] }}</td>
                                <td class="table-td text-right text-gray-600">{{ RecordsClear::TABLES[$table][2] ? $row['trashed'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <h3 class="mt-6 text-sm font-semibold text-gray-900">Uploaded files</h3>
            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                @foreach ($inventory['files'] as $table => $f)
                    <li>{{ $f['count'] }} {{ \Illuminate\Support\Str::plural($f['label'], $f['count']) }} ({{ RecordsClear::size($f['bytes']) }})</li>
                @endforeach
                @if ($inventory['stray']['count'] > 0)
                    <li>{{ $inventory['stray']['count'] }} other {{ \Illuminate\Support\Str::plural('file', $inventory['stray']['count']) }} already in those folders with no record pointing to {{ $inventory['stray']['count'] === 1 ? 'it' : 'them' }} ({{ RecordsClear::size($inventory['stray']['bytes']) }})</li>
                @endif
                <li class="font-medium text-gray-900">Total: {{ $inventory['file_count'] }} {{ \Illuminate\Support\Str::plural('file', $inventory['file_count']) }}, {{ RecordsClear::size($inventory['file_bytes']) }}</li>
            </ul>
            @if ($inventory['missing'] > 0)
                <p class="mt-2 text-xs text-gray-600">{{ $inventory['missing'] }} {{ \Illuminate\Support\Str::plural('file', $inventory['missing']) }} named on a record {{ $inventory['missing'] === 1 ? 'is' : 'are' }} already missing from disk, so there is nothing to delete for {{ $inventory['missing'] === 1 ? 'it' : 'them' }}.</p>
            @endif
        </x-card>

        <x-card data-clear-kept>
            <h2 class="card-title mb-4">Kept</h2>
            <ul class="space-y-1 text-sm text-gray-700">
                @foreach ($kept as $line)
                    <li>{{ $line }}</li>
                @endforeach
                <li>Every stored backup file, the exports folder, and the automatic copies folder</li>
                <li>Personnel photos and registration letters (they belong to accounts)</li>
            </ul>
        </x-card>

        <x-card>
            <h2 class="card-title mb-4">Before anything is deleted</h2>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700">
                <li>Your Data Privacy Password and the confirmation phrase are checked.</li>
                <li>
                    A full backup is made with Download Backup's own routine and saved under Stored Backups as
                    <span class="font-mono text-xs">{{ \App\Http\Controllers\BackupController::PRE_CLEAR_PREFIX }}…zip</span>.
                    It also holds the uploaded files listed above, so restoring it ("Records only") brings back the records and their photos.
                    It is checked: it must open, contain every table, hold exactly as many rows as the database, and contain every file at its full size.
                    It is never removed by the 20-backup limit.
                </li>
                <li><span class="font-medium">If that backup can't be verified, nothing is deleted</span>, and this screen shows why.</li>
                @if ($snapshotDir)
                    <li>
                        Because automatic copies are set up on this PC, the backup and the files are also copied into a
                        <span class="font-mono text-xs">pre-clear-…</span> folder in <span class="font-mono text-xs">{{ $snapshotDir }}</span>.
                    </li>
                @endif
                <li>The records are deleted in one transaction (all or nothing), then their files. A details file beside the backup lists everything deleted, and an Activity Log entry records who did it and when.</li>
            </ol>
        </x-card>

        <x-card>
            <h2 class="card-title mb-4">Confirm</h2>

            @if ($blockedReason)
                <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" data-clear-blocked>{{ $blockedReason }}</p>
            @endif

            {{-- config('backup.max_file_bytes'): a host's per-file limit (InfinityFree: 10 MB). Warn at 90% of it. --}}
            @if ($maxFileBytes > 0 && $copyEstimate >= 0.9 * $maxFileBytes)
                @php $limitLabel = rtrim(rtrim(number_format($maxFileBytes / 1048576, 1), '0'), '.') . ' MB'; @endphp
                <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" data-clear-size-warning>
                    The copy taken first will be about {{ RecordsClear::size($copyEstimate) }}.
                    If this server limits files to {{ $limitLabel }}, the copy can't be written and clearing will stop before anything is deleted.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.backup.clear-records.run') }}" class="space-y-4"
                  data-confirm-form="Clear all records now? A verified copy is taken first; the records and files listed on this page are then deleted.">
                @csrf
                <div>
                    <x-password-input name="data_privacy_password" id="clear_data_privacy_password" label="Data Privacy Password"
                                      autocomplete="off" :disabled="(bool) $blockedReason" />
                </div>

                <div>
                    <label for="clear_confirmation" class="form-label">
                        Type <span class="font-mono font-semibold">{{ RecordsClear::PHRASE }}</span> to confirm
                    </label>
                    <input type="text" id="clear_confirmation" name="confirmation" autocomplete="off" spellcheck="false"
                           {{ $blockedReason ? 'disabled' : '' }} class="form-input">
                    @error('confirmation')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col gap-4 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('admin.backup.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" id="clear-submit" disabled class="btn btn-danger-solid">
                        Clear All Records
                    </button>
                </div>
            </form>
        </x-card>
    </div>

    @push('scripts')
        <script>
            // Same gate as Restore: the button stays disabled until the exact
            // phrase is typed and a password is entered (re-checked server-side).
            document.addEventListener('DOMContentLoaded', function () {
                var phrase = @json(RecordsClear::PHRASE);
                var confirmInput = document.getElementById('clear_confirmation');
                var passwordInput = document.getElementById('clear_data_privacy_password');
                var submitButton = document.getElementById('clear-submit');
                if (! confirmInput || confirmInput.disabled) return;

                function sync() {
                    submitButton.disabled = ! (confirmInput.value === phrase && passwordInput.value.length > 0);
                }
                confirmInput.addEventListener('input', sync);
                passwordInput.addEventListener('input', sync);
                sync();
            });
        </script>
    @endpush
@endsection

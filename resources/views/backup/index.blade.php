@extends('layouts.app')

@section('title', 'Backup & Restore - ASCOT DRRMS')
@section('heading', 'Backup & Restore')

@section('breadcrumb')
    <x-back-link href="{{ route('admin.dashboard') }}">Back to dashboard</x-back-link>
@endsection

@section('content')
    {{-- The Data Privacy Password, set here by the Admin (App\Support\DataPrivacyPassword).
         Not set yet: this card comes first and the three actions below wait for it. --}}
    <x-card class="mb-8 {{ $dataPrivacyPasswordConfigured ? '' : 'border-amber-200 bg-amber-50' }}" data-privacy-password-card>
        <details @if (! $dataPrivacyPasswordConfigured || $errors->hasAny(['account_password', 'new_data_privacy_password'])) open @endif>
            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                <span>
                    <span class="card-title">{{ $dataPrivacyPasswordConfigured ? 'Data Privacy Password' : 'Set the Data Privacy Password' }}</span>
                    <span class="mt-1 block text-sm text-gray-600">
                        @if ($dataPrivacyPasswordConfigured)
                            Set. Download Backup, Restore and Clear All Records ask for it. Open this to change it.
                        @else
                            A second password, separate from your login, that Download Backup, Restore and Clear All Records ask for. Set it to turn them on.
                        @endif
                    </span>
                </span>
            </summary>
            <form method="POST" action="{{ route('admin.backup.privacy-password') }}" data-ajax class="mt-4 grid gap-4 md:grid-cols-3">
                @csrf
                @if ($dataPrivacyPasswordConfigured)
                    <x-password-input name="data_privacy_password" id="current_data_privacy_password" label="Current Data Privacy Password" autocomplete="off" required />
                @else
                    <x-password-input name="account_password" id="account_password" label="Your login password" autocomplete="current-password" required />
                @endif
                <x-password-input name="new_data_privacy_password" id="new_data_privacy_password" label="New Data Privacy Password" autocomplete="new-password" required />
                <x-password-input name="new_data_privacy_password_confirmation" id="new_data_privacy_password_confirmation" label="Type it again" autocomplete="new-password" required />
                <div class="md:col-span-3">
                    <p class="form-help mb-3">At least 8 characters, and not the same as your login password. Keep it somewhere safe: it is stored only as a hash and can't be shown again.</p>
                    <button type="submit" class="btn btn-primary">{{ $dataPrivacyPasswordConfigured ? 'Change password' : 'Set password' }}</button>
                </div>
            </form>
        </details>
    </x-card>

    <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
        <div class="grid min-w-0 flex-1 grid-cols-1 gap-6 xl:grid-cols-2">
            <x-card class="{{ $dataPrivacyPasswordConfigured ? '' : 'opacity-50' }}">
                <h2 class="card-title mb-4">Download Backup</h2>
                <p class="mb-4 text-sm text-gray-600">
                    Makes a complete copy of all ASCOT-DRRMS data (campuses, personnel, buildings/areas,
                    incidents, drills, and related records) — not Laravel's internal system tables. A copy is also
                    saved on the server (see Stored Backups) so you can come back to it later without keeping the
                    download yourself.
                </p>

                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-medium">This .zip is password-protected with a fixed password:
                        <span class="font-mono font-semibold">ASCOTDRRMS2026</span>.</p>
                    <p>Right-click the downloaded file → Extract All (Windows) or double-click it
                        (macOS Archive Utility) and enter that password when prompted — no extra software needed.
                        This is a fixed, non-secret value built into the app — not a real secret like your Data
                        Privacy Password — so still store the downloaded file somewhere access-controlled and
                        delete it when you no longer need it.</p>
                </div>

                <form method="POST" action="{{ route('admin.backup.download') }}" data-confirm-form="Download this backup archive?" class="space-y-4">
                    @csrf
                    <div>
                        <x-password-input name="data_privacy_password" id="download_data_privacy_password" label="Data Privacy Password"
                                          autocomplete="off" :disabled="! $dataPrivacyPasswordConfigured">
                            @error('backup_zip')
                                <p class="form-error">{{ $message }}</p>
                            @enderror
                        </x-password-input>
                    </div>

                    <button type="submit" {{ $dataPrivacyPasswordConfigured ? '' : 'disabled' }}
                            class="btn btn-primary w-full sm:w-auto">
                        Download Backup
                    </button>
                </form>
            </x-card>

            <x-card class="{{ $dataPrivacyPasswordConfigured ? '' : 'opacity-50' }}">
                <h2 class="card-title mb-4">Restore from Backup</h2>

                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-medium">This permanently replaces current data with the backup's.</p>
                    <p>Choose below what is replaced. It cannot be undone — if you haven't already, download a fresh
                        backup of the current data before proceeding.</p>
                </div>

                <form method="POST" action="{{ route('admin.backup.restore') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    <div>
                        <label for="backup_file" class="form-label">Backup file (.zip)</label>
                        <p class="text-sm leading-6 text-gray-600">
                            Accepts the .zip archive produced by Download Backup above (password-protected with
                            <span class="font-mono">ASCOTDRRMS2026</span>, unlocked automatically; older ones with
                            <span class="font-mono">ASCOTDRRMS</span> too). A backup made before the move to
                            Supabase (a .zip or a plain .sql) is still accepted.
                        </p>
                        <input type="file" id="backup_file" name="backup_file" accept=".sql,.zip" required
                               {{ $dataPrivacyPasswordConfigured ? '' : 'disabled' }}
                               class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink hover:file:bg-accent-soft-hover disabled:cursor-not-allowed">
                        @error('backup_file')
                            <p class="form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    @include('backup._restore-mode', ['prefix' => 'upload'])
                    @error('mode')
                        <p class="form-error">{{ $message }}</p>
                    @enderror

                    <div>
                        <label for="confirmation" class="form-label">
                            Type <span class="font-mono font-semibold">RESTORE</span> to confirm
                        </label>
                        <input type="text" id="confirmation" name="confirmation" autocomplete="off"
                               {{ $dataPrivacyPasswordConfigured ? '' : 'disabled' }}
                               class="form-input">
                    </div>

                    <div>
                        <x-password-input name="data_privacy_password" id="restore_data_privacy_password" label="Data Privacy Password"
                                          autocomplete="off" :disabled="! $dataPrivacyPasswordConfigured" />
                    </div>

                    <button type="submit" id="restore-submit" disabled
                            class="btn btn-danger-solid w-full sm:w-auto">
                        Restore Database
                    </button>
                </form>
            </x-card>

            @if ($snapshots['configured'])
                {{-- Automatic copies (php artisan backup:snapshot): read-only list; restoring from one is a manual job. --}}
                @php $snapStatus = $snapshots['status']; @endphp
                <x-card class="xl:col-span-2" data-snapshots>
                    <h2 class="card-title">Automatic Copies on This PC</h2>
                    <p class="caption mb-4">
                        Every 3 hours while this PC is signed in: a checked copy of the database and of the uploaded files, in
                        <span class="font-mono text-xs">{{ \App\Support\Backup\Snapshot::dir() }}</span>.
                        Kept: every copy from the last 48 hours, plus the last copy of each of the 14 days before.
                    </p>

                    <dl class="mb-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-gray-600">Newest database copy</dt>
                            <dd class="font-medium text-gray-900">{{ $snapshots['newest'] ? $snapshots['newest']->format('M j, g:i A') . ' (' . $snapshots['newest']->diffForHumans() . ')' : 'None yet' }}</dd>
                            @isset($snapStatus['last_dump'])
                                <dd class="text-xs text-gray-600">{{ $snapStatus['last_dump']['tables'] }} {{ \Illuminate\Support\Str::plural('table', $snapStatus['last_dump']['tables']) }} · {{ number_format($snapStatus['last_dump']['rows']) }} {{ \Illuminate\Support\Str::plural('row', $snapStatus['last_dump']['rows']) }} (latest run)</dd>
                            @endisset
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600">Uploaded files copied</dt>
                            <dd class="font-medium text-gray-900">{{ $snapshots['files_at'] ? $snapshots['files_at']->format('M j, g:i A') : 'Not yet' }}</dd>
                            @isset($snapStatus['files'])
                                <dd class="text-xs text-gray-600">{{ number_format($snapStatus['files']['files']) }} {{ \Illuminate\Support\Str::plural('file', $snapStatus['files']['files']) }} · {{ \App\Support\Backup\Snapshot::size($snapStatus['files']['bytes']) }}</dd>
                            @endisset
                        </div>
                        <div>
                            <dt class="text-xs text-gray-600">Last attempt</dt>
                            <dd class="font-medium {{ ($snapStatus['last_outcome'] ?? '') === 'failed' ? 'text-red-700' : 'text-gray-900' }}">
                                {{ isset($snapStatus['last_attempt_at']) ? \Carbon\CarbonImmutable::parse($snapStatus['last_attempt_at'])->setTimezone(config('app.timezone'))->format('M j, g:i A') . ' · ' . ucfirst($snapStatus['last_outcome'] ?? '') : 'Never run' }}
                            </dd>
                            @if (($snapStatus['last_outcome'] ?? '') === 'failed')
                                <dd class="text-xs text-red-700">{{ $snapStatus['last_error'] }}</dd>
                            @endif
                        </div>
                    </dl>

                    @foreach ($snapStatus['warnings'] ?? [] as $warning)
                        <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">{{ $warning }}</p>
                    @endforeach

                    @if ($snapshots['dumps']->isNotEmpty())
                        <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-200">
                            <table class="min-w-full text-sm">
                                <thead><tr><th class="table-th">Database copy</th><th class="table-th">Taken</th><th class="table-th text-right">Size</th></tr></thead>
                                <tbody>
                                    @foreach ($snapshots['dumps'] as $name => $dump)
                                        <tr>
                                            <td class="table-td font-mono text-xs">{{ $name }}</td>
                                            <td class="table-td">{{ $dump['at']->format('M j, g:i A') }}</td>
                                            <td class="table-td text-right">{{ \App\Support\Backup\Snapshot::size($dump['bytes']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>
            @endif

            <x-card class="border border-red-200 xl:col-span-2" data-clear-entry>
                <h2 class="card-title">Clear All Records</h2>
                <p class="caption mb-4">
                    Deletes every incident, building/area, inspection, drill and alert (including Trash) and their uploaded files.
                    Accounts, campuses, schools, the activity log and backups are kept. The next screen lists exactly what would go;
                    nothing is deleted until you confirm there.
                </p>
                <a href="{{ route('admin.backup.clear-records') }}" class="btn btn-danger">Review what would be cleared</a>
            </x-card>
        </div>

        <div class="w-full xl:w-96 xl:shrink-0">
            @php
                $formatBackupSize = function (int $bytes): string {
                    return $bytes >= 1048576
                        ? number_format($bytes / 1048576, 2) . ' MB'
                        : number_format($bytes / 1024, 1) . ' KB';
                };
            @endphp

            <x-card class="xl:sticky xl:top-8">
                <h2 class="card-title">Stored Backups</h2>
                <p class="caption mb-4">
                    Up to {{ $maxStoredBackups }} kept — the oldest is removed automatically once that cap is reached. Copies taken before Clear All Records are never removed and don't count toward it.
                </p>

                @if ($recentBackups->isEmpty() && $oldBackups->isEmpty())
                    <x-empty-state icon="inbox" message="No backups yet — click Download Backup to create one." />
                @else
                    <div class="max-h-[32rem] space-y-6 overflow-y-auto pr-4">
                        @if ($recentBackups->isNotEmpty())
                            <div>
                                <p class="eyebrow mb-4">Recent</p>
                                <div class="space-y-4">
                                    @foreach ($recentBackups as $backup)
                                        @include('backup._backup-panel-item', ['backup' => $backup, 'formatBackupSize' => $formatBackupSize])
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($oldBackups->isNotEmpty())
                            <div class="{{ $recentBackups->isNotEmpty() ? 'border-t border-gray-100 pt-4' : '' }}">
                                <p class="eyebrow mb-4">Older</p>
                                <div class="space-y-4">
                                    @foreach ($oldBackups as $backup)
                                        @include('backup._backup-panel-item', ['backup' => $backup, 'formatBackupSize' => $formatBackupSize])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var fileInput = document.getElementById('backup_file');
                var confirmInput = document.getElementById('confirmation');
                var passwordInput = document.getElementById('restore_data_privacy_password');
                var submitButton = document.getElementById('restore-submit');

                if (! fileInput || fileInput.disabled) {
                    return;
                }

                function syncSubmitState() {
                    var hasFile = fileInput.files && fileInput.files.length > 0;
                    var confirmed = confirmInput.value === 'RESTORE';
                    var hasPassword = passwordInput.value.length > 0;
                    submitButton.disabled = ! (hasFile && confirmed && hasPassword);
                }

                fileInput.addEventListener('change', syncSubmitState);
                confirmInput.addEventListener('input', syncSubmitState);
                passwordInput.addEventListener('input', syncSubmitState);
                syncSubmitState();
            });
        </script>
    @endpush
@endsection

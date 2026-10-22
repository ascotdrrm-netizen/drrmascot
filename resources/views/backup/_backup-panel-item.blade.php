<div class="rounded-lg border border-gray-200 p-4">
    <p class="truncate text-sm font-medium leading-6 text-gray-900" title="{{ $backup->filename }}">{{ $backup->filename }}</p>
    @if (str_starts_with($backup->filename, \App\Http\Controllers\BackupController::PRE_CLEAR_PREFIX))
        <p class="mb-1 text-xs font-medium text-red-700" data-pre-clear>Taken before Clear All Records · never removed automatically</p>
    @endif
    <p class="text-xs text-gray-600">{{ $backup->created_at?->diffForHumans() ?? '—' }}</p>
    <p class="mb-4 text-xs text-gray-600">{{ $backup->creator->full_name ?? 'Unknown' }} &middot; {{ $formatBackupSize($backup->file_size) }}</p>

    <div data-tap-row class="flex flex-wrap items-start gap-4">
        <a href="{{ route('admin.backup.stored.download', $backup) }}" data-confirm="Download this stored backup?" class="link-muted text-xs">Download</a>

        <details>
            <summary class="link-danger cursor-pointer list-none text-xs">Restore</summary>
            <div class="mt-4 space-y-4 rounded-xl border border-red-200 bg-red-50 p-4">
                <form method="POST" action="{{ route('admin.backup.stored.restore', $backup) }}" data-confirm-form="Restore from this backup? Every record, the activity log, settings and hotlines will be replaced with the backup's. Accounts, campuses and schools stay." class="space-y-4">
                    @csrf
                    <p class="text-xs text-red-700">Replaces every record, the activity log, settings and hotlines. Accounts, campuses and schools stay.</p>
                    <div>
                        <label class="block text-xs font-medium leading-6 text-gray-700">
                            Type <span class="font-mono font-semibold">RESTORE</span>
                        </label>
                        <input type="text" name="confirmation" autocomplete="off" aria-label="Type RESTORE to confirm"
                               class="form-input form-input-sm">
                    </div>
                    <div>
                        <label for="stored-{{ $backup->getKey() }}-data_privacy_password" class="block text-xs font-medium leading-6 text-gray-700">Data Privacy Password</label>
                        {{-- Errors for this field already show on the main Restore form above. --}}
                        <x-password-input name="data_privacy_password" id="stored-{{ $backup->getKey() }}-data_privacy_password"
                                          autocomplete="off" size="sm" :show-error="false" aria-label="Data Privacy Password" />
                    </div>
                    <button type="submit" class="btn btn-danger-solid btn-sm w-full">
                        Restore Database
                    </button>
                </form>
            </div>
        </details>
    </div>
</div>

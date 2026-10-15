{{--
    What a restore replaces. Shared by the upload Restore form and each stored
    backup's Restore. "records" is the default and matches Clear All Records;
    "everything" is the full-database restore, for recovering a damaged database.
    $prefix: unique id prefix. $compact: the narrow Stored Backups panel.
--}}
@php $compact = $compact ?? false; @endphp
<fieldset class="space-y-2" data-restore-mode>
    <legend class="{{ $compact ? 'block text-xs font-medium leading-6 text-gray-700' : 'form-label' }}">Restore what?</legend>

    <label for="{{ $prefix }}-mode-records" class="flex cursor-pointer gap-4 rounded-lg border border-gray-200 bg-white p-4 has-[:checked]:border-accent-line has-[:checked]:bg-accent-soft">
        <input type="radio" id="{{ $prefix }}-mode-records" name="mode" value="records" checked class="mt-1 h-4 w-4 shrink-0 text-accent focus:ring-accent-line">
        <span class="text-sm">
            <span class="block font-medium text-gray-900">Records only <span class="font-normal text-gray-600">(recommended)</span></span>
            @unless ($compact)
                <span class="mt-1 block text-gray-700">
                    Replaces every record — incidents, buildings/areas, inspections, drills, alerts and their reports and attachments, Trash included.
                    Accounts, campuses, schools and the activity log stay as they are, so a <span class="font-medium">Records Cleared</span> entry is kept.
                </span>
                <span class="mt-1 block text-gray-600">If the backup needs a campus or school that no longer exists, nothing is restored and you're told which.</span>
            @else
                <span class="mt-1 block text-xs text-gray-600">Accounts, campuses, schools and the activity log stay as they are.</span>
            @endunless
        </span>
    </label>

    <label for="{{ $prefix }}-mode-everything" class="flex cursor-pointer gap-4 rounded-lg border border-gray-200 bg-white p-4 has-[:checked]:border-red-600 has-[:checked]:bg-red-50">
        <input type="radio" id="{{ $prefix }}-mode-everything" name="mode" value="everything" class="mt-1 h-4 w-4 shrink-0 text-red-600 focus:ring-red-500">
        <span class="text-sm">
            <span class="block font-medium text-gray-900">Everything in the backup</span>
            @unless ($compact)
                <span class="mt-1 block text-gray-700">For recovering a damaged database.</span>
                <span class="mt-1 block font-medium text-red-700">Also replaces accounts and passwords: anyone added since the backup is gone and changed passwords revert. Campuses, schools and the activity log are replaced too.</span>
            @else
                <span class="mt-1 block text-xs font-medium text-red-700">Also replaces accounts and passwords; anyone added since is gone.</span>
            @endunless
        </span>
    </label>
</fieldset>

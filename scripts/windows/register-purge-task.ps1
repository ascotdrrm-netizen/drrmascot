<#
    Registers the Windows scheduled task that runs `php artisan trash:purge`
    (see config/trash.php and App\Support\TrashPurge): records that have been
    in Trash longer than TRASH_RETENTION_DAYS are deleted PERMANENTLY, with
    their photos and files.

    Without -Register it only prints the task it would create. Preview what a
    run would delete first:

        C:\xampp\php\php.exe artisan trash:purge --dry-run

        powershell -ExecutionPolicy Bypass -File scripts\windows\register-purge-task.ps1
        powershell -ExecutionPolicy Bypass -File scripts\windows\register-purge-task.ps1 -Register
        powershell -ExecutionPolicy Bypass -File scripts\windows\register-purge-task.ps1 -Unregister

    Runs as the signed-in user only while signed in (no stored password), no
    window. Fires daily at 01:00 and 20 minutes after sign-in, so a day the
    laptop was off is caught up at the next sign-in. Extra runs are harmless:
    the command deletes only what is past the window, and a run that starts
    while another holds its lock does nothing.
#>
param(
    [switch]$Register,
    [switch]$Unregister,
    [string]$Php = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$taskName = 'ASCOT-DRRMS Trash Purge'
$taskPath = '\ASCOT-DRRMS\'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$user = "$env:USERDOMAIN\$env:USERNAME"

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
    "Removed $taskPath$taskName"
    return
}

if (-not (Test-Path $Php)) { throw "PHP not found at $Php (pass -Php)" }

$launcher = Join-Path $repo 'scripts\windows\run-hidden.vbs'
$action = New-ScheduledTaskAction -Execute "$env:WINDIR\System32\wscript.exe" `
    -Argument "`"$launcher`" `"$Php artisan trash:purge`"" `
    -WorkingDirectory $repo

# Once a day (only fires while the user is signed in).
$daily = New-ScheduledTaskTrigger -Daily -At '01:00'

# 20 minutes after this user signs in (after the snapshot task's 15).
$logon = New-ScheduledTaskTrigger -AtLogOn -User $user
$logon.Delay = 'PT20M'

$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10)

$task = New-ScheduledTask -Action $action -Trigger @($daily, $logon) -Principal $principal -Settings $settings `
    -Description 'ASCOT-DRRMS: permanently deletes records in Trash past the retention period, with their files (php artisan trash:purge). Each deletion is in the Activity Log as System (automatic).'

if ($Register) {
    Register-ScheduledTask -TaskName $taskName -TaskPath $taskPath -InputObject $task -Force | Out-Null
    "Registered $taskPath$taskName"
} else {
    "Would register $taskPath$taskName (run again with -Register):"
    ''
    "  Runs as        : $user, only while signed in, no stored password, standard rights"
    "  Program        : $env:WINDIR\System32\wscript.exe"
    "  Arguments      : `"$launcher`" `"$Php artisan trash:purge`""
    "  Start in       : $repo"
    "  Trigger 1      : daily at 01:00"
    "  Trigger 2      : at sign-in of $user, delayed 20 minutes"
    "  Missed start   : run as soon as possible afterwards"
    "  On battery     : runs, and is not stopped when the charger is unplugged"
    "  Already running: a new start is skipped"
    "  Time limit     : 30 minutes"
    "  If it can't start: retry 3 times, 10 minutes apart"
    ''
    'Task XML:'
    $task | Export-ScheduledTask
}

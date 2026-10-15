<#
    Registers the Windows scheduled task that runs `php artisan backup:snapshot`
    (see config/backup.php and App\Support\Backup\Snapshot).

    Without -Register it only prints the task it would create.

        powershell -ExecutionPolicy Bypass -File scripts\windows\register-snapshot-task.ps1
        powershell -ExecutionPolicy Bypass -File scripts\windows\register-snapshot-task.ps1 -Register
        powershell -ExecutionPolicy Bypass -File scripts\windows\register-snapshot-task.ps1 -Unregister

    Runs as the signed-in user only while signed in (no stored password), no
    window. Fires every hour and 15 minutes after sign-in; the command itself
    takes a dump only when the newest verified one is 170+ minutes old, so a
    copy lands every 3 hours and a failed run is retried within the hour.
#>
param(
    [switch]$Register,
    [switch]$Unregister,
    [string]$Php = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$taskName = 'ASCOT-DRRMS Backup Snapshot'
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
    -Argument "`"$launcher`" `"$Php artisan backup:snapshot`"" `
    -WorkingDirectory $repo

# Every hour, all day, every day (only fires while the user is signed in).
$hourly = New-ScheduledTaskTrigger -Daily -At '00:00'
$hourly.Repetition = (New-ScheduledTaskTrigger -Once -At '00:00' -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition

# 15 minutes after this user signs in (after a reboot, planned or not).
$logon = New-ScheduledTaskTrigger -AtLogOn -User $user
$logon.Delay = 'PT15M'

$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 10)

$task = New-ScheduledTask -Action $action -Trigger @($hourly, $logon) -Principal $principal -Settings $settings `
    -Description 'ASCOT-DRRMS: verified database dump + copy of uploaded files (php artisan backup:snapshot). Results show on the Admin dashboard and Backup & Restore.'

if ($Register) {
    Register-ScheduledTask -TaskName $taskName -TaskPath $taskPath -InputObject $task -Force | Out-Null
    "Registered $taskPath$taskName"
} else {
    "Would register $taskPath$taskName (run again with -Register):"
    ''
    "  Runs as        : $user, only while signed in, no stored password, standard rights"
    "  Program        : $env:WINDIR\System32\wscript.exe"
    "  Arguments      : `"$launcher`" `"$Php artisan backup:snapshot`""
    "  Start in       : $repo"
    "  Trigger 1      : daily from 00:00, repeat every 1 hour for 1 day"
    "  Trigger 2      : at sign-in of $user, delayed 15 minutes"
    "  Missed start   : run as soon as possible afterwards"
    "  On battery     : runs, and is not stopped when the charger is unplugged"
    "  Already running: a new start is skipped"
    "  Time limit     : 30 minutes"
    "  If it can't start: retry 3 times, 10 minutes apart"
    ''
    'Task XML:'
    $task | Export-ScheduledTask
}

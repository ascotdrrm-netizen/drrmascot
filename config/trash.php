<?php

/*
|--------------------------------------------------------------------------
| Trash retention
|--------------------------------------------------------------------------
|
| Deleted buildings/areas, incidents, drills and disaster advisories wait in
| Trash, where they can be restored. `php artisan trash:purge` (daily: see
| routes/console.php and scripts/windows/register-purge-task.ps1) removes
| each one permanently, with its photos and files, once it has been in Trash
| this many days, counted from when it was deleted. One value for all four.
| See App\Support\TrashPurge.
|
*/

return [

    // At least 1: a 0 would empty Trash on every run.
    'retention_days' => max(1, (int) env('TRASH_RETENTION_DAYS', 30)),

    // The Trash pages trust the countdown only while the purge has succeeded
    // this recently; past it they say retention is not currently running.
    'stale_after_hours' => 48,

];

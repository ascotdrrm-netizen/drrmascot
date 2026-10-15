<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One-off export of the drill data the Drill Tracker rebuild drops: the old
 * participant evaluations (names and scores), the workflow columns on drills
 * (status, school, conduct record, *_by / *_at) and the drill_photos rows.
 *
 * Written to the PRIVATE disk (storage/app/private/exports), never the public
 * one — the evaluations file holds participant names.
 *
 *   php artisan drills:export-legacy       writes the three files
 *   2026_10_08_000000 migration (up)       refuses to run unless they exist and
 *                                          match the database row for row
 *   2026_10_08_000000 migration (down)     reads them back to restore the data
 */
final class LegacyDrillExport
{
    public const DISK = 'local';

    public const DIR = 'exports';

    public const EVALUATIONS = 'drill-evaluations-legacy.csv';

    public const WORKFLOW = 'drill-workflow-legacy.csv';

    public const PHOTOS = 'drill-photos-legacy.csv';

    /** Every drills column the rebuild drops, in export order. */
    public const WORKFLOW_COLUMNS = [
        'status', 'school_id', 'date_conducted', 'time_conducted', 'observations', 'return_reason',
        'scheduled_by', 'scheduled_by_name', 'scheduled_at',
        'acknowledged_by', 'acknowledged_by_name', 'acknowledged_at',
        'submitted_by', 'submitted_by_name', 'submitted_at',
        'reviewed_by', 'reviewed_by_name', 'reviewed_at',
    ];

    public const EVALUATION_COLUMNS = ['evaluation_id', 'drill_id', 'personnel_id', 'participant_name', 'personnel_full_name', 'pre_score', 'post_score'];

    public const PHOTO_COLUMNS = ['photo_id', 'drill_id', 'path', 'caption', 'created_at'];

    /**
     * Write all three files (any previous copy is kept alongside with a
     * timestamp suffix, never overwritten). Returns [file => rows written].
     *
     * @return array<string, int>
     */
    public static function export(): array
    {
        if (! Schema::hasTable('drill_evaluations') || ! Schema::hasColumn('drills', 'status')) {
            throw new RuntimeException('Nothing to export: the drill workflow tables are already gone.');
        }

        $evaluations = DB::table('drill_evaluations as e')
            ->leftJoin('personnel as p', 'p.personnel_id', '=', 'e.personnel_id')
            ->orderBy('e.evaluation_id')
            ->get(['e.evaluation_id', 'e.drill_id', 'e.personnel_id', 'e.participant_name', 'p.full_name as personnel_full_name', 'e.pre_score', 'e.post_score']);

        // Trashed drills included: they keep their history too.
        $drills = DB::table('drills')->orderBy('drill_id')->get(array_merge(['drill_id', 'drill_type', 'scheduled_date'], self::WORKFLOW_COLUMNS));

        $photos = DB::table('drill_photos')->orderBy('photo_id')->get(self::PHOTO_COLUMNS);

        return [
            self::EVALUATIONS => self::write(self::EVALUATIONS, self::EVALUATION_COLUMNS, $evaluations),
            // drill_type and scheduled_date are kept on drills; they're in the file only so it reads on its own.
            self::WORKFLOW => self::write(self::WORKFLOW, array_merge(['drill_id', 'drill_type', 'scheduled_date'], self::WORKFLOW_COLUMNS), $drills),
            self::PHOTOS => self::write(self::PHOTOS, self::PHOTO_COLUMNS, $photos),
        ];
    }

    /**
     * Refuse unless every file exists and holds exactly the rows currently in
     * the database. A database with nothing to lose (a fresh install, the test
     * database) needs no files.
     */
    public static function assertComplete(): void
    {
        $expected = [
            self::EVALUATIONS => DB::table('drill_evaluations')->count(),
            self::WORKFLOW => DB::table('drills')->count(),
            self::PHOTOS => DB::table('drill_photos')->count(),
        ];

        if (array_sum($expected) === 0) {
            return;
        }

        foreach ($expected as $file => $count) {
            if (! Storage::disk(self::DISK)->exists(self::path($file))) {
                throw new RuntimeException("Drill data export missing: storage/app/private/" . self::path($file) . '. Run "php artisan drills:export-legacy" first.');
            }
            $rows = count(self::read($file));
            if ($rows !== $count) {
                throw new RuntimeException("Drill data export out of date: {$file} has {$rows} rows, the database has {$count}. Run \"php artisan drills:export-legacy\" again.");
            }
        }
    }

    /**
     * Rows of one export file as associative arrays ('' read back as null).
     * Missing file = no rows.
     *
     * @return array<int, array<string, ?string>>
     */
    public static function read(string $file): array
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists(self::path($file))) {
            return [];
        }

        $handle = fopen($disk->path(self::path($file)), 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($v) => $v === '' ? null : $v, array_combine($header, $line));
        }
        fclose($handle);

        return $rows;
    }

    public static function path(string $file): string
    {
        return self::DIR . '/' . $file;
    }

    protected static function write(string $file, array $columns, $rows): int
    {
        $disk = Storage::disk(self::DISK);
        $path = self::path($file);
        if ($disk->exists($path)) {
            $disk->move($path, self::DIR . '/' . pathinfo($file, PATHINFO_FILENAME) . '.previous-' . now()->format('Ymd-His') . '.csv');
        }
        $disk->makeDirectory(self::DIR);

        $handle = fopen($disk->path($path), 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // so Excel reads the UTF-8 names correctly
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($c) => $row->{$c}, $columns));
        }
        fclose($handle);

        return count($rows);
    }
}

<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The app's tables as plain rows, read and written the same way on every
 * database (PostgreSQL on Supabase, SQLite in the tests). Used by Backup &
 * Restore, Clear All Records and the automatic snapshot, in place of the old
 * MySQL-only SHOW TABLES / SHOW CREATE TABLE / SET FOREIGN_KEY_CHECKS code.
 *
 * Order matters because foreign keys stay ON: rows are written parents first
 * and deleted children first. A column that points at its own table, or into
 * a foreign-key loop, is written empty first and filled in once every row is
 * in (it must be nullable — every such column in this app is).
 */
class DataSet
{
    /**
     * Laravel's own bookkeeping tables, plus `backups` (this feature's own
     * list of backup FILES, not app data: restoring an old backup must not
     * forget backups made after it).
     */
    public const EXCLUDED_TABLES = [
        'migrations', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'users', 'backups', 'sqlite_sequence', 'phone_verifications',
        // The Data Privacy Password's hash: never in a backup, never replaced by a restore.
        'system_secrets',
    ];

    /** @var array<string, list<array{column: string, table: string}>> */
    protected static array $foreignKeyCache = [];

    /**
     * Every app table, parents before children.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $names = collect(Schema::getTables())
            ->filter(fn ($t) => ($t['schema'] ?? null) === null || in_array($t['schema'], ['public', 'main'], true))
            ->pluck('name')
            ->reject(fn ($name) => in_array($name, self::EXCLUDED_TABLES, true))
            ->unique()->sort()->values()->all();

        return self::parentsFirst($names);
    }

    /** @return array<string, int> */
    public static function rowCounts(?array $tables = null): array
    {
        return collect($tables ?? self::tables())->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /**
     * Writes each table to one JSON-lines file (one row per line) and returns
     * [table => [path, rows]]. The caller deletes the files.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function exportToFiles(?array $tables = null): array
    {
        $out = [];
        foreach ($tables ?? self::tables() as $table) {
            $path = tempnam(sys_get_temp_dir(), 'drrms_' . $table . '_');
            if ($path === false) {
                throw new RuntimeException("Could not create a temporary file for $table in " . sys_get_temp_dir() . '.');
            }
            $handle = fopen($path, 'wb');
            $rows = 0;
            $query = DB::table($table);
            $key = self::primaryKey($table);
            $key ? $query->orderBy($key) : $query->orderByRaw('1');
            foreach ($query->cursor() as $row) {
                fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                $rows++;
            }
            fclose($handle);
            $out[$table] = [$path, $rows];
        }

        return $out;
    }

    /** One table's rows back from its JSON-lines text. @return list<array<string, mixed>> */
    public static function parseLines(string $jsonl, string $table): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $jsonl) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                throw new RuntimeException("Line " . ($n + 1) . " of $table in the backup is not readable.");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Replaces the given tables' rows with $rows (table => list of rows), all
     * or nothing, foreign keys on. Tables in $tables but not in $rows end up
     * empty. Columns the backup has but the database no longer has refuse the
     * restore (the caller names them first with unknownColumns()).
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  list<string>|null  $tables  the tables to replace (default: all app tables)
     * @return array<string, int> rows written per table
     */
    public static function replace(array $rows, ?array $tables = null): array
    {
        // Restoring a snapshot must never repeat external messages that may have
        // been accepted since the backup. Preserve them for provider reconciliation.
        $unconfirmed = [];
        foreach ($rows['broadcast_deliveries'] ?? [] as $i => $delivery) {
            if (in_array($delivery['status'], ['Pending', 'Processing'], true)) {
                $rows['broadcast_deliveries'][$i]['status'] = 'Unknown';
                $rows['broadcast_deliveries'][$i]['error'] = 'Restored from backup. Check provider history before sending another message.';
                $unconfirmed[$delivery['broadcast_id']] = true;
            }
        }
        foreach ($rows['broadcasts'] ?? [] as $i => $broadcast) {
            if (isset($unconfirmed[$broadcast['broadcast_id']])) $rows['broadcasts'][$i]['status'] = 'Needs review';
        }
        $order = self::parentsFirst($tables ?? self::tables());
        $position = array_flip($order);
        $written = [];

        DB::transaction(function () use ($rows, $order, $position, &$written) {
            foreach (array_reverse($order) as $table) {          // children first
                DB::table($table)->delete();
            }

            $later = []; // [table, key column, key value, column, value]
            foreach ($order as $table) {                         // parents first
                $tableRows = $rows[$table] ?? [];
                $written[$table] = count($tableRows);
                if ($tableRows === []) {
                    continue;
                }
                $types = self::columnTypes($table);
                $key = self::primaryKey($table);
                // Columns pointing at this table or one written after it.
                $deferred = collect(self::foreignKeys($table))
                    ->filter(fn ($fk) => $fk['table'] === $table || ($position[$fk['table']] ?? -1) > $position[$table])
                    ->pluck('column')->unique()->all();

                $batch = [];
                foreach ($tableRows as $row) {
                    $row = self::cast($row, $types);
                    foreach ($deferred as $column) {
                        if (($row[$column] ?? null) !== null) {
                            if ($key === null) {
                                throw new RuntimeException("Table $table has no primary key, so $column can't be restored in two steps.");
                            }
                            $later[] = [$table, $key, $row[$key], $column, $row[$column]];
                            $row[$column] = null;
                        }
                    }
                    $batch[] = $row;
                    if (count($batch) === 200) {
                        DB::table($table)->insert($batch);
                        $batch = [];
                    }
                }
                if ($batch) {
                    DB::table($table)->insert($batch);
                }
            }

            foreach ($later as [$table, $key, $id, $column, $value]) {
                DB::table($table)->where($key, $id)->update([$column => $value]);
            }

            self::resetSequences($order);
        });

        return $written;
    }

    /**
     * Backup columns the current database doesn't have, as "table.column".
     *
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @return list<string>
     */
    public static function unknownColumns(array $rows): array
    {
        $unknown = [];
        foreach ($rows as $table => $tableRows) {
            if ($tableRows === [] || ! Schema::hasTable($table)) {
                continue;
            }
            $current = Schema::getColumnListing($table);
            $columns = array_keys(array_merge(...array_map(fn ($r) => $r, $tableRows)));
            foreach (array_diff($columns, $current) as $column) {
                $unknown[] = "$table.$column";
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * PostgreSQL keeps a counter per auto-numbered key; rows inserted with
     * their old ids don't move it, so the next new record would collide.
     */
    public static function resetSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tables as $table) {
            $key = self::primaryKey($table);
            if ($key === null) {
                continue;
            }
            $sequence = DB::selectOne('select pg_get_serial_sequence(?, ?) as s', [$table, $key])->s ?? null;
            if ($sequence) {
                DB::statement("select setval(?, coalesce((select max(\"{$key}\") from \"{$table}\"), 1), (select max(\"{$key}\") from \"{$table}\") is not null)", [$sequence]);
            }
        }
    }

    /** The table's single-column primary key, or null. */
    public static function primaryKey(string $table): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    /** @return list<array{column: string, table: string}> */
    public static function foreignKeys(string $table): array
    {
        return self::$foreignKeyCache[$table] ??= collect(Schema::getForeignKeys($table))
            ->flatMap(fn ($fk) => collect($fk['columns'])->map(fn ($c) => ['column' => $c, 'table' => $fk['foreign_table']]))
            ->values()->all();
    }

    /** About how many bytes the tables take, for the Clear screen's size warning. */
    public static function approximateBytes(): int
    {
        return match (DB::getDriverName()) {
            'pgsql' => (int) DB::selectOne("select coalesce(sum(pg_total_relation_size(c.oid)), 0) as b from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r'")->b,
            'sqlite' => (int) (DB::selectOne('pragma page_count')->page_count ?? 0) * (int) (DB::selectOne('pragma page_size')->page_size ?? 0),
            default => 0,
        };
    }

    // ------------------------------------------------------------------------

    /** @param list<string> $tables @return list<string> */
    protected static function parentsFirst(array $tables): array
    {
        $set = array_flip($tables);
        $needs = [];
        foreach ($tables as $table) {
            $needs[$table] = collect(self::foreignKeys($table))->pluck('table')
                ->filter(fn ($parent) => $parent !== $table && isset($set[$parent]))->unique()->values()->all();
        }

        $order = [];
        while ($needs) {
            $ready = array_keys(array_filter($needs, fn ($parents) => array_diff($parents, $order) === []));
            if ($ready === []) {
                // A loop: take the remaining tables by name; replace() defers
                // the columns that point forward, so the loop still restores.
                $ready = array_keys($needs);
            }
            sort($ready);
            foreach ($ready as $table) {
                $order[] = $table;
                unset($needs[$table]);
            }
        }

        return $order;
    }

    /** @return array<string, string> column => type name */
    protected static function columnTypes(string $table): array
    {
        return collect(Schema::getColumns($table))->mapWithKeys(fn ($c) => [$c['name'] => strtolower($c['type_name'])])->all();
    }

    /** Values as the database expects them (SQLite keeps booleans as 0/1). */
    protected static function cast(array $row, array $types): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }
            $type = $types[$column] ?? '';
            if (in_array($type, ['bool', 'boolean'], true)) {
                $row[$column] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_array($value)) {
                $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $row;
    }
}

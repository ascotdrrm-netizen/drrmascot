<?php

namespace App\Support\Supabase;

use Illuminate\Support\Facades\DB;

/**
 * Supabase serves every table in the "public" schema through its Data API
 * (PostgREST) to anyone holding the project's public (anon) key, and that key
 * is in the browser for Realtime. This turns Row Level Security on for every
 * table, with no policies, and takes the API roles' rights away, so the anon
 * and authenticated roles can read and change nothing. Laravel connects as
 * the database owner ("postgres"), which RLS does not restrict.
 *
 * Runs after every `php artisan migrate` (AppServiceProvider listens for
 * MigrationsEnded), so a table added later is locked down too. PostgreSQL
 * only; on SQLite (tests) it does nothing.
 */
class LockDown
{
    /** @return list<string> the tables it changed */
    public static function run(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $open = self::unprotectedTables();
        foreach ($open as $table) {
            DB::statement('alter table public.' . self::quote($table) . ' enable row level security');
        }

        // The API roles exist only on Supabase; a plain PostgreSQL (CI) has neither.
        $roles = collect(DB::select("select rolname from pg_roles where rolname in ('anon', 'authenticated')"))->pluck('rolname')->all();
        if ($roles) {
            $list = implode(', ', $roles);
            DB::statement("revoke all on all tables in schema public from {$list}");
            DB::statement("revoke all on all sequences in schema public from {$list}");
            DB::statement("revoke all on all functions in schema public from {$list}");
            DB::statement("alter default privileges in schema public revoke all on tables from {$list}");
            DB::statement("alter default privileges in schema public revoke all on sequences from {$list}");
            DB::statement("alter default privileges in schema public revoke all on functions from {$list}");
        }

        return $open;
    }

    /** Tables in "public" that still have Row Level Security off (empty when safe). */
    public static function unprotectedTables(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        return collect(DB::select(
            "select c.relname as name from pg_class c
             join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relrowsecurity"
        ))->pluck('name')->all();
    }

    protected static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

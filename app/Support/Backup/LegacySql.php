<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Schema;

/**
 * Reads the INSERT statements of a backup made before the move to Supabase
 * (MySQL SQL text: backticked names, backslash escapes) as plain rows, so an
 * old backup restores through the same path as a new one. Nothing in the SQL
 * is ever run: only its values are read.
 */
class LegacySql
{
    public static function looksLikeSql(string $sql): bool
    {
        return (bool) preg_match('/^\s*(--|\/\*|SET|DROP|CREATE|INSERT)/i', $sql);
    }

    /**
     * Rows per table from every INSERT statement. A statement without a
     * column list (mysqldump's style) takes the table's current columns.
     *
     * @return array<string, list<array<string, ?string>>>
     */
    public static function rows(string $sql): array
    {
        $rows = [];
        foreach (self::statements($sql) as $statement) {
            $statement = trim(preg_replace('/^(\s*--[^\n]*\n)+/', '', $statement));
            if (preg_match('/^CREATE TABLE `?(\w+)`?/i', $statement, $c)) {
                $rows[$c[1]] ??= [];

                continue;
            }
            if (! preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s*(\(([^)]*)\))?\s*VALUES\s*/i', $statement, $m)) {
                continue;
            }
            $table = $m[1];
            $columns = ! empty($m[3])
                ? array_map(fn ($c) => trim($c, " `\"\t\r\n"), explode(',', $m[3]))
                : (Schema::hasTable($table) ? Schema::getColumnListing($table) : []);
            if ($columns === []) {
                // A table this app doesn't have, with no column list: nothing to read.
                $rows[$table] ??= [];

                continue;
            }

            foreach (self::tuples(rtrim(substr($statement, strlen($m[0])), "; \t\r\n")) as $values) {
                if (count($values) !== count($columns)) {
                    throw new \RuntimeException("A row in $table has " . count($values) . ' values for ' . count($columns) . ' columns.');
                }
                $rows[$table][] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    /**
     * Splits a SQL script on ";" without splitting inside quoted strings
     * (backslash-escaped and doubled quotes).
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $current .= $char;

            if ($inString) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];

                    continue;
                }
                if ($char === $quote) {
                    $inString = false;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString = true;
                $quote = $char;

                continue;
            }
            if ($char === ';') {
                $statements[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * The values of each "( … )" group in a VALUES list: quoted strings
     * unescaped (backslash escapes and doubled quotes), NULL as null, anything
     * else (numbers) as written.
     *
     * @return \Generator<list<?string>>
     */
    public static function tuples(string $values): \Generator
    {
        $length = strlen($values);
        $i = 0;
        $escapes = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", 'b' => "\x08"];

        while ($i < $length) {
            while ($i < $length && $values[$i] !== '(') {
                $i++;
            }
            if ($i >= $length) {
                return;
            }
            $i++;
            $row = [];
            while (true) {
                while ($i < $length && ctype_space($values[$i])) {
                    $i++;
                }
                if ($values[$i] === "'" || $values[$i] === '"') {
                    $quote = $values[$i++];
                    $value = '';
                    while ($i < $length) {
                        $c = $values[$i];
                        if ($c === '\\' && $i + 1 < $length) {
                            $next = $values[$i + 1];
                            $value .= $escapes[$next] ?? $next;
                            $i += 2;
                        } elseif ($c === $quote && ($values[$i + 1] ?? '') === $quote) {
                            $value .= $quote;
                            $i += 2;
                        } elseif ($c === $quote) {
                            $i++;
                            break;
                        } else {
                            $value .= $c;
                            $i++;
                        }
                    }
                    $row[] = $value;
                } else {
                    $start = $i;
                    while ($i < $length && $values[$i] !== ',' && $values[$i] !== ')') {
                        $i++;
                    }
                    $token = trim(substr($values, $start, $i - $start));
                    $row[] = strcasecmp($token, 'NULL') === 0 ? null : $token;
                }
                while ($i < $length && ctype_space($values[$i])) {
                    $i++;
                }
                if (($values[$i] ?? ')') === ',') {
                    $i++;

                    continue;
                }
                $i++; // ')'
                break;
            }
            yield $row;
        }
    }
}

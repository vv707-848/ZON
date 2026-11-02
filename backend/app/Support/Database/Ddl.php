<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/** Schema rules Blueprint cannot express, for the migrations (MySQL 8). */
final class Ddl
{
    /**
     * A CHECK constraint keeping a string column to known values — the same strings as the PHP enum of that
     * column (App\Enums). A new value needs a new migration that replaces the constraint.
     *
     * @param  list<string>  $values
     */
    public static function in(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$column}_check` CHECK (`{$column}` IN ({$list}))");
    }

    /** A multi-valued index on a JSON array of strings (MySQL 8.0.17+): `WHERE JSON_OVERLAPS(col, …)` / MEMBER OF use it. */
    public static function jsonArrayIndex(string $table, string $column, int $length = 80): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_{$column}_mvi` ((CAST(`{$column}` AS CHAR({$length}) ARRAY)))");
    }

    /** A CHECK constraint keeping a number column inside [min, max]. */
    public static function between(string $table, string $column, int $min, int $max): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$column}_check` CHECK (`{$column}` BETWEEN {$min} AND {$max})");
    }
}

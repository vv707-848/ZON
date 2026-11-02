<?php

namespace App\SuperAgent;

use Illuminate\Support\Facades\DB;

/** Runs a template on the read-only connection (ai_reader), 5 seconds at most, 200 rows at most. */
final class ReportRunner
{
    public const MAX_ROWS = 200;

    /**
     * @param  array<string, mixed>  $params  already checked
     * @return list<array<string, mixed>>
     */
    public function run(Template $template, array $params): array
    {
        [$sql, $bindings] = $template->query($params);
        $connection = DB::connection((string) config('zon.report_connection'));
        $connection->statement('SET SESSION MAX_EXECUTION_TIME = 5000');

        $rows = $connection->select("SELECT * FROM ({$sql}) AS report LIMIT ".self::MAX_ROWS, $bindings);

        return array_map(function (object $row): array {
            $values = [];
            foreach ((array) $row as $column => $value) {
                $values[$column] = is_string($value) && is_numeric($value) ? $value + 0 : $value;   // MySQL sends decimals as strings
            }

            return $values;
        }, $rows);
    }
}

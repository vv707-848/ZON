<?php

namespace App\SuperAgent;

/**
 * An approved report: a fixed SQL on the ai_v_* views with declared parameters. The model only CHOOSES a template
 * and its parameters (report_select for a question, report_explore step by step); it never writes SQL.
 */
abstract class Template
{
    /** snake_case name the model picks */
    abstract public function name(): string;

    abstract public function title(): string;

    /** One sentence for the model's catalog and the panel. */
    abstract public function description(): string;

    /** @return array<string, Param> */
    abstract public function params(): array;

    /** @return array<string, string> result column => Arabic header */
    abstract public function columns(): array;

    /**
     * @param  array<string, mixed>  $params  already checked
     * @return array{0: string, 1: list<mixed>} SQL with ? placeholders and its bindings
     */
    abstract public function query(array $params): array;

    /**
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    public function checkParams(array $given): array
    {
        return Param::checkAll($this->params(), $given);
    }

    /** @return array<string, mixed> the template as the model sees it in the catalog */
    public function catalogEntry(): array
    {
        return [
            'report' => $this->name(),
            'description' => $this->description(),
            'params' => (object) array_map(fn (Param $p) => $p->schema(), $this->params()),   // {} when none, never []
        ];
    }

    /** The start of the period «last N days» as a SQL datetime. */
    protected static function since(int $days): string
    {
        return now()->subDays($days)->toDateTimeString();
    }

    /**
     * A JSON_TABLE that turns a JSON list column of a view into one row per item, named $as.
     * The CAST is required: MySQL 8.0 rejects a view's JSON column inside JSON_TABLE
     * (error 1210 «Incorrect arguments to JSON_TABLE»); the same column of the base table works.
     */
    protected static function items(string $column, string $as, int $length): string
    {
        return "JSON_TABLE(CAST({$column} AS JSON), '\$[*]' COLUMNS ({$as} VARCHAR({$length}) PATH '\$'))";
    }
}

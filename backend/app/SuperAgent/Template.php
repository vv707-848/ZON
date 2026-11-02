<?php

namespace App\SuperAgent;

use InvalidArgumentException;

/**
 * An approved report: a fixed SQL on the ai_v_* views with declared parameters. The model only CHOOSES a template
 * and its parameters (report_select); it never writes SQL.
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
        $unknown = array_diff(array_keys($given), array_keys($this->params()));
        if ($unknown !== []) {
            throw new InvalidArgumentException('unknown parameters: '.implode(', ', $unknown));
        }
        $checked = [];
        foreach ($this->params() as $name => $param) {
            $checked[$name] = $param->check($name, $given[$name] ?? null);
        }

        return $checked;
    }

    /** @return array<string, mixed> the template as report_select sees it */
    public function catalogEntry(): array
    {
        return [
            'report' => $this->name(),
            'description' => $this->description(),
            'params' => array_map(fn (Param $p) => $p->schema(), $this->params()),
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

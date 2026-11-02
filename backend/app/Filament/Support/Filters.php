<?php

namespace App\Filament\Support;

use App\Support\Trace;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/** Filters shared by the panel's tables. */
final class Filters
{
    /** «رقم التتبع»: the rows one user action left (trace_id, or properties.trace_id in the audit log). */
    public static function trace(string $column = 'trace_id'): Filter
    {
        return Filter::make('trace')
            ->schema([TextInput::make('trace')->label('رقم التتبع')->regex(Trace::PATTERN)])
            ->query(fn (Builder $query, array $data) => $query->when($data['trace'] ?? null, fn (Builder $q, string $id) => $q->where($column, $id)))
            ->indicateUsing(fn (array $data) => ($data['trace'] ?? null) ? 'التتبع: '.$data['trace'] : null);
    }

    public static function dateRange(string $column, string $label): Filter
    {
        return Filter::make($column)
            ->schema([DatePicker::make('from')->label("{$label} من"), DatePicker::make('until')->label("{$label} إلى")])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '<=', $date)))
            ->indicateUsing(fn (array $data) => array_filter([
                ($data['from'] ?? null) ? "{$label} من {$data['from']}" : null,
                ($data['until'] ?? null) ? "{$label} إلى {$data['until']}" : null,
            ]));
    }
}

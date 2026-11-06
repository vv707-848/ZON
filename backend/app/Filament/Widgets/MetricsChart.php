<?php

namespace App\Filament\Widgets;

use App\Models\DailyMetric;
use Filament\Widgets\ChartWidget;

/**
 * A chart of the last 30 days from daily_metrics (ComputeDailyMetrics, every night at 00:15): one line per
 * dimension of a metric, or one line per listed metric.
 */
abstract class MetricsChart extends ChartWidget
{
    protected ?string $maxHeight = '260px';

    private const COLORS = ['#1B4D3E', '#C9A227', '#2E7D9A', '#B5543C', '#6B5CA5', '#7A8B3D'];

    /** @return array<string, string> metric (or "metric:dimension") => line label */
    abstract protected function series(): array;

    protected function getData(): array
    {
        $days = collect(range(29, 0))->map(fn (int $ago) => now('Asia/Riyadh')->subDays($ago + 1)->toDateString());
        $metrics = array_map(fn (string $key) => explode(':', $key)[0], array_keys($this->series()));
        $rows = DailyMetric::query()->whereIn('metric', $metrics)->where('stat_date', '>=', $days->first())->get()
            ->groupBy(fn (DailyMetric $m) => $m->metric.':'.$m->dimension);

        $datasets = [];
        $i = 0;
        foreach ($this->series() as $key => $label) {
            $points = str_contains($key, ':')
                ? ($rows[$key] ?? collect())->keyBy(fn (DailyMetric $m): string => $m->stat_date->toDateString())
                : $rows->filter(fn ($_, $group) => str_starts_with($group, $key.':'))->flatten()->groupBy(fn (DailyMetric $m): string => $m->stat_date->toDateString())
                    ->map(fn ($group) => (object) ['value' => $group->sum('value')]);
            $color = self::COLORS[$i++ % count(self::COLORS)];
            $datasets[] = [
                'label' => $label,
                'data' => $days->map(fn (string $day) => (float) ($points[$day]->value ?? 0))->all(),
                'borderColor' => $color,
                'backgroundColor' => $color,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $days->map(fn (string $d) => substr($d, 5))->all()];
    }

    protected function getType(): string
    {
        return 'line';
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\AiRequest;
use App\Models\SearchMiss;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The AI today: calls, full answers, fallbacks, time, search misses. */
class AiToday extends StatsOverviewWidget
{
    protected ?string $heading = 'الذكاء اليوم';

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $today = AiRequest::query()->where('created_at', '>=', now('Asia/Riyadh')->startOfDay()->utc());
        $total = (clone $today)->count();
        $full = (clone $today)->whereIn('status', ['ok', 'cached'])->count();

        return [
            Stat::make('نداءات', $total),
            Stat::make('ردود كاملة', $total === 0 ? '—' : round($full * 100 / $total).'٪')->color('success'),
            Stat::make('بديل / مهلة', (clone $today)->whereIn('status', ['fallback', 'timeout', 'rejected'])->count())->color('danger'),
            Stat::make('متوسط الزمن', (int) (clone $today)->where('status', 'ok')->avg('latency_ms').' ms'),
            Stat::make('بحث بلا نتيجة', SearchMiss::query()->where('created_at', '>=', now('Asia/Riyadh')->startOfDay()->utc())->count()),
        ];
    }
}

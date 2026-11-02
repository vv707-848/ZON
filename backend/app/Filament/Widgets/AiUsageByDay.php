<?php

namespace App\Filament\Widgets;

use App\Enums\AiCallStatus;
use App\Models\AiRequest;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/** AI calls per day by outcome, over 7 or 30 days (live from ai_requests). */
class AiUsageByDay extends ChartWidget
{
    protected ?string $heading = 'نداءات الذكاء يومياً';

    protected ?string $maxHeight = '280px';

    public ?string $filter = '7';

    protected function getFilters(): ?array
    {
        return ['7' => 'آخر 7 أيام', '30' => 'آخر 30 يوماً'];
    }

    protected function getData(): array
    {
        $days = collect(range((int) $this->filter - 1, 0))->map(fn (int $ago) => now()->subDays($ago)->toDateString());
        $rows = AiRequest::query()->where('created_at', '>=', now()->subDays((int) $this->filter)->startOfDay())
            ->select(DB::raw('DATE(created_at) AS day'), 'status', DB::raw('COUNT(*) AS calls'))->groupBy('day', 'status')->get()
            ->groupBy('status');
        $colors = ['ok' => '#1B4D3E', 'cached' => '#2E7D9A', 'degraded' => '#C9A227', 'fallback' => '#B5543C', 'timeout' => '#8B2F2F', 'rejected' => '#6B5CA5'];

        $datasets = [];
        foreach (AiCallStatus::cases() as $status) {
            $byDay = ($rows[$status->value] ?? collect())->keyBy('day');
            $datasets[] = ['label' => $status->label(), 'data' => $days->map(fn ($d) => (int) ($byDay[$d]->calls ?? 0))->all(),
                'backgroundColor' => $colors[$status->value], 'borderColor' => $colors[$status->value]];
        }

        return ['datasets' => $datasets, 'labels' => $days->map(fn (string $d) => substr($d, 5))->all()];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

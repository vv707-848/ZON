<?php

namespace App\Filament\Widgets;

/** AI calls per day by outcome. */
class AiCallsChart extends MetricsChart
{
    protected ?string $heading = 'نداءات الذكاء — آخر 30 يوماً';

    protected static ?int $sort = 9;

    protected function series(): array
    {
        return ['ai.calls:ok' => 'كامل', 'ai.calls:cached' => 'من الذاكرة', 'ai.calls:degraded' => 'بلا نموذج', 'ai.calls:fallback' => 'بديل', 'ai.calls:timeout' => 'مهلة'];
    }
}

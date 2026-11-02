<?php

namespace App\Filament\Widgets;

/** Supply and demand per day: listings published, requests posted, wishes saved, sales. */
class ActivityChart extends MetricsChart
{
    protected ?string $heading = 'النشاط — آخر 30 يوماً';

    protected static ?int $sort = 7;

    protected function series(): array
    {
        return ['listings.published:' => 'عروض نُشرت', 'requests.created:' => 'طلبات', 'preferences.created:' => 'رغبات', 'listings.sold:' => 'بيع'];
    }
}

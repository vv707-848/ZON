<?php

namespace App\Filament\Widgets;

/** New accounts per day and role. */
class SignupsChart extends MetricsChart
{
    protected ?string $heading = 'التسجيلات — آخر 30 يوماً';

    protected static ?int $sort = 6;

    protected function series(): array
    {
        return ['signups:seeker' => 'باحث', 'signups:broker' => 'وسيط', 'signups:owner' => 'مالك', 'signups:developer' => 'مطوّر'];
    }
}

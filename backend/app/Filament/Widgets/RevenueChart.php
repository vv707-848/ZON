<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Staff;
use App\Support\Permissions\Permission;

/** Paid money per day (riyals), by what was bought. */
class RevenueChart extends MetricsChart
{
    protected ?string $heading = 'الإيراد بالريال — آخر 30 يوماً';

    protected static ?int $sort = 8;

    public static function canView(): bool
    {
        return Staff::can(Permission::ViewPayments);
    }

    protected function series(): array
    {
        return ['revenue:subscription' => 'اشتراكات', 'revenue:featured' => 'تمييز', 'revenue:urgent_listing' => 'عاجل للعروض', 'revenue:urgent_request' => 'طلبات عاجلة', 'revenue:ad_licence' => 'رسوم الترخيص'];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

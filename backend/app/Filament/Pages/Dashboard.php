<?php

namespace App\Filament\Pages;

use App\Filament\Support\Staff;
use App\Filament\Widgets;
use App\Support\Permissions\Permission;
use Filament\Pages\Dashboard as BaseDashboard;

/** «الرئيسية»: statistics of everything — accounts, content, money, AI, and what waits for staff. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'الرئيسية';

    public static function canAccess(): bool
    {
        return Staff::can(Permission::ViewDashboard);
    }

    public function getWidgets(): array
    {
        return [
            Widgets\FollowUpOverview::class,
            Widgets\AccountsOverview::class,
            Widgets\ContentOverview::class,
            Widgets\FinanceOverview::class,
            Widgets\AiToday::class,
            Widgets\SignupsChart::class,
            Widgets\ActivityChart::class,
            Widgets\RevenueChart::class,
            Widgets\AiCallsChart::class,
        ];
    }

    public function getColumns(): int
    {
        return 2;
    }
}

<?php

namespace App\Filament\Widgets;

use App\Enums\CustomerRole;
use App\Models\Customer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Accounts by role, new this week, suspended. */
class AccountsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'الحسابات';

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $byRole = Customer::query()->whereNull('anonymized_at')->groupBy('role')->pluck(\DB::raw('COUNT(*)'), 'role');
        $stats = [Stat::make('كل الحسابات', $byRole->sum())->description(Customer::query()->where('created_at', '>=', now()->subWeek())->count().' جديد هذا الأسبوع')];
        foreach (CustomerRole::cases() as $role) {
            $stats[] = Stat::make($role->label(), (int) ($byRole[$role->value] ?? 0));
        }
        $stats[] = Stat::make('موقوفون', Customer::query()->where('status', 'suspended')->count())->color('danger');

        return $stats;
    }
}

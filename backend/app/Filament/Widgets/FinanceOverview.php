<?php

namespace App\Filament\Widgets;

use App\Enums\CommissionStatus;
use App\Enums\PaymentStatus;
use App\Filament\Support\Staff;
use App\Models\CommissionDue;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Money;
use App\Support\Permissions\Permission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Money: today, this month, live subscriptions, commissions owed to Zon. */
class FinanceOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'المالية';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Staff::can(Permission::ViewPayments);
    }

    protected function getStats(): array
    {
        $paid = fn ($from) => (int) Payment::query()->whereIn('status', [PaymentStatus::Paid, PaymentStatus::Unapplied])->where('paid_at', '>=', $from)->sum('amount_halalas');
        $sar = fn (int $halalas) => number_format((float) (new Money($halalas))->riyals(), 2).' ر.س';

        return [
            Stat::make('إيراد اليوم', $sar($paid(now('Asia/Riyadh')->startOfDay()->utc())))->color('success'),
            Stat::make('إيراد الشهر', $sar($paid(now('Asia/Riyadh')->startOfMonth()->utc()))),
            Stat::make('اشتراكات سارية', Subscription::query()->current()->count()),
            Stat::make('عمولات مستحقة', $sar((int) CommissionDue::query()->where('status', CommissionStatus::Due)->sum('amount_halalas')))->color('warning'),
            Stat::make('دفعات غير مطبّقة', Payment::query()->where('status', PaymentStatus::Unapplied)->count())->description('تحتاج استرداداً')->color('danger'),
        ];
    }
}

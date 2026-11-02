<?php

namespace App\Filament\Widgets;

use App\Enums\ReportStatus;
use App\Enums\TicketStatus;
use App\Models\ContentReport;
use App\Models\Customer;
use App\Models\DeletionRequest;
use App\Models\IndexEvent;
use App\Models\LegacyClaim;
use App\Models\SupportTicket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** What waits for a person, first on the dashboard. */
class FollowUpOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'بانتظار المتابعة';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('بلاغات جديدة', ContentReport::query()->whereIn('status', [ReportStatus::New, ReportStatus::Reviewing])->count())->color('danger'),
            Stat::make('تذاكر دعم مفتوحة', SupportTicket::query()->where('status', TicketStatus::Open)->count())->color('warning'),
            Stat::make('مطوّرون بانتظار العقد', Customer::query()->where('role', 'developer')->where('status', 'pending')->count()),
            Stat::make('طلبات استعادة', LegacyClaim::query()->where('status', 'pending')->count()),
            Stat::make('حذف حساب في المهلة', DeletionRequest::query()->whereNull('cancelled_at')->whereNull('purged_at')->count()),
            Stat::make('إشارات فهرسة فاشلة', IndexEvent::query()->failed()->count())->color('danger'),
        ];
    }
}

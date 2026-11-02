<?php

namespace App\Filament\Widgets;

use App\Enums\ListingStatus;
use App\Models\BuyerPreference;
use App\Models\Listing;
use App\Models\Project;
use App\Models\SearchRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Listings by status, projects, requests and wishes. */
class ContentOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'المحتوى';

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $byStatus = Listing::query()->groupBy('status')->pluck(\DB::raw('COUNT(*)'), 'status');

        return [
            Stat::make('عروض منشورة', (int) ($byStatus[ListingStatus::Published->value] ?? 0))->color('success'),
            Stat::make('بانتظار قرار المعلن', (int) ($byStatus[ListingStatus::PendingReview->value] ?? 0)),
            Stat::make('غير نشطة / متوقفة', (int) ($byStatus[ListingStatus::Inactive->value] ?? 0) + (int) ($byStatus[ListingStatus::Paused->value] ?? 0)),
            Stat::make('مخفية للإشراف', (int) ($byStatus[ListingStatus::Hidden->value] ?? 0))->color('danger'),
            Stat::make('منتهية الترخيص', (int) ($byStatus[ListingStatus::LicenceExpired->value] ?? 0))->color('warning'),
            Stat::make('تم البيع', (int) ($byStatus[ListingStatus::Sold->value] ?? 0)),
            Stat::make('مشاريع منشورة', Project::query()->published()->count()),
            Stat::make('طلبات مفتوحة', SearchRequest::query()->where('status', 'published')->count())
                ->description(SearchRequest::query()->where('status', 'published')->where('urgent', true)->count().' عاجل'),
            Stat::make('رغبات نشطة', BuyerPreference::query()->where('is_active', true)->count()),
        ];
    }
}

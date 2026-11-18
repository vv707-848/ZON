<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\BandedDemand;

/** الغرف المطلوبة (تقرير سوق): كم شخصاً يطلب كل عدد غرف نوم، وكم عرضاً منشوراً به. */
final class RoomsDemand extends BandedDemand
{
    public function name(): string
    {
        return 'rooms_demand';
    }

    public function title(): string
    {
        return 'الغرف المطلوبة (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل عدد غرف نوم: عدد الطالبين الذين ذكروه (أقل عدد في الطلب) من البحث الدلالي والرغبات والطلبات، ونسبتهم، والعروض المنشورة بالعدد نفسه، والطلب لكل عرض.';
    }

    public function columns(): array
    {
        return ['band' => 'غرف النوم', 'demand' => 'الطالبون', 'demand_share' => 'نسبتهم', 'listings' => 'عروض منشورة', 'demand_per_listing' => 'الطلب لكل عرض'];
    }

    protected function bands(array $params): array
    {
        return [['غرفة', 1, 2], ['غرفتان', 2, 3], ['3 غرف', 3, 4], ['4 غرف', 4, 5], ['5 غرف', 5, 6], ['6 غرف فأكثر', 6, 100]];
    }

    protected function demandColumn(): string
    {
        return 'bedrooms';
    }

    protected function listingColumn(): string
    {
        return 'bedrooms';
    }
}

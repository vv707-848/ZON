<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\BandedDemand;

/** شرائح الميزانية (تقرير سوق): كم شخصاً يطلب في كل شريحة سعر، وكم عرضاً منشوراً فيها. */
final class BudgetBands extends BandedDemand
{
    /** Sale prices and yearly rents, in riyals: [label, from, to). */
    private const SALE = [['أقل من 500 ألف', 0, 500_000], ['500–750 ألف', 500_000, 750_000], ['750 ألف – مليون', 750_000, 1_000_000],
        ['1 – 1.5 مليون', 1_000_000, 1_500_000], ['1.5 – 2 مليون', 1_500_000, 2_000_000], ['2 – 3 ملايين', 2_000_000, 3_000_000],
        ['3 – 5 ملايين', 3_000_000, 5_000_000], ['5 ملايين فأكثر', 5_000_000, 1_000_000_000_000]];

    private const RENT = [['أقل من 25 ألف', 0, 25_000], ['25–40 ألف', 25_000, 40_000], ['40–60 ألف', 40_000, 60_000], ['60–80 ألف', 60_000, 80_000],
        ['80–120 ألف', 80_000, 120_000], ['120–200 ألف', 120_000, 200_000], ['200 ألف فأكثر', 200_000, 1_000_000_000_000]];

    public function name(): string
    {
        return 'budget_bands';
    }

    public function title(): string
    {
        return 'شرائح الميزانية (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل شريحة سعر (بيع أو إيجار سنوي): عدد الطالبين بأعلى ميزانية فيها من البحث الدلالي والرغبات والطلبات، ونسبتهم، والعروض المنشورة في الشريحة نفسها، والطلب لكل عرض.';
    }

    public function columns(): array
    {
        return ['band' => 'شريحة الميزانية (ريال)', 'demand' => 'الطالبون', 'demand_share' => 'نسبتهم', 'listings' => 'عروض منشورة', 'demand_per_listing' => 'الطلب لكل عرض'];
    }

    protected function bands(array $params): array
    {
        return $params['purpose'] === 'rent' ? self::RENT : self::SALE;
    }

    protected function demandColumn(): string
    {
        return 'budget_max';
    }

    protected function listingColumn(): string
    {
        return 'price';
    }
}

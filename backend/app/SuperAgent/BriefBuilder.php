<?php

namespace App\SuperAgent;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Models\MarketBrief;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A developer's market brief («تقارير المطوّرين»): the approved market reports of one city, run on the read-only views
 * in a fixed order and frozen as they ran, with the headline numbers read from their rows — no model writes any of
 * it, so every number is a row's. Each row stands for the minimum group at least (MarketTemplate), as in the panel.
 */
final class BriefBuilder
{
    /** report => [heading, what it tells a developer] — in the order the brief shows them */
    public const SECTIONS = [
        'demand_map' => ['أين الطلب', 'الأحياء والأنواع التي يطلبها الناس أكثر مما يُعرض فيها: الطلب لكل عرض فوق 1 يعني أن الطالبين أكثر من المعروض.'],
        'budget_bands' => ['كم يدفعون', 'شرائح أعلى ميزانية ذكرها الطالبون، والمعروض في كل شريحة: الشريحة التي يكثر طالبوها ويقل معروضها هي السعر الذي تُبنى له الوحدة.'],
        'rooms_demand' => ['ماذا يطلبون', 'عدد غرف النوم الذي يطلبه الناس مقابل المعروض به: يحدد مزيج الوحدات في المشروع.'],
        'demand_trend' => ['اتجاه الطلب', 'الطلب شهراً بشهر، ونسبة تغيّره عن الشهر السابق.'],
        'real_budget_index' => ['الميزانية مقابل السعر', 'وسيط أعلى ميزانية الطالبين مقابل وسيط أسعار المعروض في كل حي: تحت 1 يعني أن الأسعار أعلى مما يستطيعه الطالبون.'],
        'sale_prices' => ['أسعار البيع الفعلية', 'ما بيع فعلاً بالسعر الذي ذكره المعلن، مقابل سعر عرضه.'],
        'unmet_features' => ['الطلب غير المغطى', 'رغبات نشطة لا يقابلها أي عرض منشور في حيها ونوعها ضمن ميزانيتها: طلب ينتظر من يبني له.'],
    ];

    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * @param  array{city: string, neighborhood?: ?string, purpose: string, property_type?: ?string, days: int}  $scope
     * @param  array{customer_id?: ?int, buyer_name: string, analyst_note?: ?string, price_halalas?: ?int, valid_days: int}  $sale
     */
    public function make(array $scope, array $sale, User $staff): MarketBrief
    {
        $sections = $this->sections($scope);
        $brief = MarketBrief::query()->create([
            'customer_id' => $sale['customer_id'] ?? null,
            'buyer_name' => $sale['buyer_name'],
            'city' => $scope['city'],
            'neighborhood' => $scope['neighborhood'] ?? null,
            'purpose' => $scope['purpose'],
            'property_type' => $scope['property_type'] ?? null,
            'days' => $scope['days'],
            'sections' => $sections,
            'highlights' => self::highlights($sections, $scope['purpose']),
            'analyst_note' => ($sale['analyst_note'] ?? '') === '' ? null : $sale['analyst_note'],
            'price_halalas' => $sale['price_halalas'] ?? null,
            'token' => Str::random(40),
            'expires_at' => now()->addDays($sale['valid_days']),
            'created_by' => $staff->id,
        ]);
        $brief->forceFill(['number' => sprintf('ZON-MB-%06d', $brief->id)])->save();

        return $brief;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array{report: string, title: string, why: string, columns: array<string, string>, rows: list<array<string, mixed>>}>
     */
    public function sections(array $scope): array
    {
        $given = ['city' => $scope['city'], 'neighborhood' => $scope['neighborhood'] ?? null, 'purpose' => $scope['purpose'],
            'property_type' => $scope['property_type'] ?? null, 'days' => $scope['days'], 'months' => max(1, min(24, intdiv($scope['days'], 30))), 'limit' => 30];
        $sections = [];
        foreach (self::SECTIONS as $name => [$title, $why]) {
            $template = Catalog::find($name);
            if (! $template instanceof MarketTemplate || ($name === 'sale_prices' && $scope['purpose'] !== Purpose::Sale->value)) {
                continue;   // sale prices are of sales only
            }
            $params = $template->checkParams(array_filter(array_intersect_key($given, $template->params()), fn ($v) => $v !== null));
            $sections[] = ['report' => $name, 'title' => $title, 'why' => $why, 'columns' => $template->columns(),
                'rows' => self::narrowed($this->runner->run($template, $params), $scope)];
        }

        return $sections;
    }

    /**
     * The rows of a report that has no neighbourhood or type parameter, kept to the brief's.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    private static function narrowed(array $rows, array $scope): array
    {
        $type = ($scope['property_type'] ?? null) === null ? null : PropertyType::from($scope['property_type']);

        return array_values(array_filter($rows, fn (array $row) => (($scope['neighborhood'] ?? null) === null || ! array_key_exists('neighborhood', $row) || $row['neighborhood'] === $scope['neighborhood'])
            && ($type === null || ! array_key_exists('property_type', $row) || in_array($row['property_type'], [$type->value, $type->label()], true))));
    }

    /**
     * The headline numbers, each read from one row as it is (no sum or ratio of our own beyond the report's).
     *
     * @param  list<array{report: string, rows: list<array<string, mixed>>}>  $sections
     * @return list<string>
     */
    public static function highlights(array $sections, string $purpose): array
    {
        $rows = array_column($sections, 'rows', 'report');
        $lines = [];
        if (($top = $rows['demand_map'][0] ?? null) !== null) {
            $lines[] = "أعلى طلب لكل عرض: {$top['neighborhood']} ({$top['property_type']}) — ".'الطلب لكل عرض منشور '.self::number($top['demand_per_listing']).'.';
        }
        if (($bands = $rows['budget_bands'] ?? []) !== []) {
            $band = collect($bands)->sortByDesc('demand')->first();
            $lines[] = 'أكثر شريحة '.($purpose === Purpose::Rent->value ? 'إيجار' : 'ميزانية')." مطلوبة: {$band['band']} ريال — ".self::percent($band['demand_share']).' من الطالبين، والعروض المنشورة فيها '.self::number($band['listings']).'.';
        }
        if (($rooms = $rows['rooms_demand'] ?? []) !== []) {
            $room = collect($rooms)->sortByDesc('demand')->first();
            $lines[] = "أكثر ما يُطلب: {$room['band']} — ".self::percent($room['demand_share']).' من الطالبين.';
        }
        $months = $rows['demand_trend'] ?? [];
        if (($last = end($months)) !== false && $last['change'] !== null) {
            $lines[] = "تغيّر الطلب في {$last['month']} عن الشهر السابق: ".($last['change'] >= 0 ? '+' : '−').self::percent(abs($last['change'])).'.';
        }
        if (($unmet = $rows['unmet_features'][0] ?? null) !== null) {
            $lines[] = "طلب بلا عرض مناسب: {$unmet['neighborhood']} — ".self::number($unmet['unmet']).' من '.self::number($unmet['wishes']).' رغبة لا يقابلها عرض منشور.';
        }

        return $lines;
    }

    private static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private static function percent(int|float $share): string
    {
        return self::number(round($share * 100, 1)).'٪';
    }
}

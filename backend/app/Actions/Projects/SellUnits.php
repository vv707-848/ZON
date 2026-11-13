<?php

namespace App\Actions\Projects;

use App\Enums\CommissionStatus;
use App\Enums\ErrorCode;
use App\Enums\MarketingContractStatus;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\CommissionDue;
use App\Models\MarketingContract;
use App\Models\ProjectUnit;
use App\Models\SaleReport;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Units of a model sold: fewer available (none left → out of the index), the contract's commission per unit, and
 * the sale reported to the market as said — its price only when he gave it (SaleReport).
 */
final class SellUnits
{
    public function __construct(private readonly IndexSync $index) {}

    public function __invoke(ProjectUnit $unit, int $count, ?string $salePrice): ProjectUnit
    {
        return DB::transaction(function () use ($unit, $count, $salePrice) {
            $unit = ProjectUnit::query()->with('project')->lockForUpdate()->findOrFail($unit->id);
            if ($count < 1 || $count > $unit->available_count) {
                throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['count' => [__('validation.between.numeric', ['attribute' => 'العدد', 'min' => 1, 'max' => $unit->available_count])]]]);
            }
            $unit->forceFill(['available_count' => $unit->available_count - $count])->save();
            SaleReport::forUnits($unit, $count, $salePrice);

            $contract = MarketingContract::query()->where('developer_id', $unit->project->developer_id)->where('status', MarketingContractStatus::Active)
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $unit->project_id))->orderByDesc('project_id')->first();
            if ($contract !== null) {
                $price = $salePrice ?? (string) $unit->price;
                $each = BigDecimal::of($price)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
                for ($i = 0; $i < $count; $i++) {
                    CommissionDue::query()->create([
                        'customer_id' => $unit->project->developer_id,
                        'source_type' => $contract->getMorphClass(),
                        'source_id' => $contract->id,
                        'project_unit_id' => $unit->id,
                        'sale_price' => $price,
                        'percent' => $contract->commission_percent,
                        'amount_halalas' => (new Money($each))->percent((string) $contract->commission_percent)->halalas,
                        'status' => CommissionStatus::Due,
                    ]);
                }
            }
            $this->index->unit($unit);

            return $unit;
        });
    }
}

<?php

namespace App\Actions\Admin;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\MarketingContractStatus;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\Customer;
use App\Models\MarketingContract;
use App\Notifications\Notices;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** A developer becomes active with his marketing contract (number, date, commission per unit sold). */
final class ActivateDeveloper
{
    /** @param array{number: string, signed_at: string, ends_at?: ?string, commission_percent: string|float, project_id?: ?int, document_path?: ?string, notes?: ?string} $contract */
    public function __invoke(Customer $developer, array $contract): MarketingContract
    {
        if ($developer->role !== CustomerRole::Developer) {
            throw new InvalidArgumentException('Only a developer account is activated by a marketing contract.');
        }

        return DB::transaction(function () use ($developer, $contract) {
            $record = MarketingContract::query()->create([
                ...$contract,
                'developer_id' => $developer->id,
                'status' => MarketingContractStatus::Active,
                'created_by' => Staff::user()->id,
            ]);
            $wasPending = $developer->status === AccountStatus::Pending;
            $developer->forceFill(['status' => AccountStatus::Active])->save();
            Audit::log('developer_activated', 'تفعيل مطوّر بعقد تسويق', $developer, ['contract' => $record->number]);
            if ($wasPending) {
                $developer->notify(Notices::accountActivated());
            }

            return $record;
        });
    }
}

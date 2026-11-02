<?php

namespace App\Jobs\Scheduled;

use App\Actions\Owner\OwnerCaseFlow;
use App\Enums\ContractStatus;
use App\Enums\OwnerCaseStage;
use App\Integrations\IntegrationException;
use App\Jobs\ScheduledJob;
use App\Models\OwnerCase;
use Illuminate\Support\Facades\Log;

/** Every 10 minutes: owners' contracts waiting for approval are asked about at REGA. */
class SyncOwnerContracts extends ScheduledJob
{
    public function handle(): void
    {
        $flow = app(OwnerCaseFlow::class);
        OwnerCase::query()->with('customer')->where('stage', OwnerCaseStage::Contract)->where('contract_status', ContractStatus::Submitted)
            ->chunkById(100, function ($cases) use ($flow) {
                foreach ($cases as $case) {
                    try {
                        $flow->sync($case);
                    } catch (IntegrationException $e) {
                        Log::warning('owner_case.sync_failed', ['case' => $case->id, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}

<?php

namespace App\Jobs;

use App\Enums\FalStatus;
use App\Integrations\IntegrationException;
use App\Integrations\Rega\RegaGateway;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Checks a broker's FAL licence with REGA (at sign-up, and nightly by RecheckBrokerLicenses). */
class CheckBrokerLicence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly int $customerId) {}

    public function handle(RegaGateway $rega): void
    {
        $broker = Customer::query()->find($this->customerId);
        if ($broker === null || $broker->fal_number === null) {
            return;
        }
        try {
            $check = $rega->checkFal($broker->fal_number);
        } catch (IntegrationException $e) {
            $this->release($this->backoff);

            return;
        }

        $status = match (true) {
            $check->valid => FalStatus::Valid,
            $check->expiresAt?->isPast() ?? false => FalStatus::Expired,
            default => FalStatus::Rejected,
        };
        $broker->forceFill(['fal_status' => $status, 'fal_checked_at' => now(), 'fal_expires_at' => $check->expiresAt])->save();
    }
}

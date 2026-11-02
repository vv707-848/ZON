<?php

namespace App\Integrations\Nafath;

use App\Enums\NafathStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SIMULATION of Nafath until the contract. The first status check answers: verified, or rejected when the
 * national id ends with 0. The id itself is never stored (only whether it ends with 0, for 10 minutes).
 */
class FakeNafathGateway implements NafathGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function start(string $nationalId): NafathChallenge
    {
        $transaction = (string) Str::uuid();
        Cache::put("nafath.sim.{$transaction}", str_ends_with($nationalId, '0'), now()->addMinutes(10));

        return new NafathChallenge($transaction, (string) random_int(10, 99));
    }

    public function status(string $transactionId): NafathStatus
    {
        $rejected = Cache::get("nafath.sim.{$transactionId}");

        return match ($rejected) {
            null => NafathStatus::None,          // unknown or expired transaction
            true => NafathStatus::Rejected,
            default => NafathStatus::Verified,
        };
    }
}

<?php

namespace App\Integrations\Nafath;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SIMULATION of Nafath until the contract, with its request's life: the first status check answers «waiting»,
 * the next «completed» — or «rejected» when the national id ends with 0 — and a request left unanswered expires
 * (config zon.nafath.request_seconds). Only whether the id ends with 0 is kept, for the request's life.
 */
class FakeNafathGateway implements NafathGateway
{
    public function start(string $nationalId): NafathChallenge
    {
        $transaction = (string) Str::uuid();
        Cache::put("nafath.sim.{$transaction}", ['rejected' => str_ends_with($nationalId, '0'), 'polls' => 0], now()->addSeconds((int) config('zon.nafath.request_seconds')));

        return new NafathChallenge($transaction, (string) random_int(10, 99));
    }

    public function status(string $transactionId): NafathAnswer
    {
        $request = Cache::get("nafath.sim.{$transactionId}");
        if (! is_array($request)) {
            return NafathAnswer::Expired;                              // unknown, or its time ran out
        }
        if ($request['polls'] === 0) {
            Cache::put("nafath.sim.{$transactionId}", ['polls' => 1] + $request, now()->addSeconds((int) config('zon.nafath.request_seconds')));

            return NafathAnswer::Waiting;
        }

        return $request['rejected'] ? NafathAnswer::Rejected : NafathAnswer::Completed;
    }
}

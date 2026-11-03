<?php

namespace App\Jobs;

use App\Integrations\Stores\Stores;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tells the store a purchase was delivered (Google: acknowledge a subscription, consume a boost so it can be bought
 * again). Google refunds a purchase left unacknowledged for three days, so this tries again for most of a day.
 */
class SettleStorePurchase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600, 7200, 14400, 28800];

    /** @param string $token the purchase token (Google) or transaction (Apple) */
    public function __construct(public readonly string $store, public readonly string $productId, public readonly string $token, public readonly bool $subscription)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Stores::for($this->store)->settle($this->productId, $this->token, $this->subscription);
    }
}

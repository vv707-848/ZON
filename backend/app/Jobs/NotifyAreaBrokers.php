<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Models\Customer;
use App\Models\SearchRequest;
use App\Notifications\Notices;
use App\Support\Blocks;
use App\Support\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A new request reaches the brokers of its city («طلبات المنطقة» switch), never its author or who he blocked; a tester's, testers only. */
class NotifyAreaBrokers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        $request = SearchRequest::query()->with('customer')->find($this->requestId);
        if ($request === null || $request->status->value !== 'published') {
            return;
        }
        $brokers = Customer::query()->where('role', CustomerRole::Broker)->where('status', AccountStatus::Active)->where('city', $request->city)
            ->whereNull('anonymized_at')->where('id', '!=', $request->customer_id)->whereNotIn('id', Blocks::blockedWith($request->customer));
        Sandbox::audience($brokers, $request->customer)
            ->chunkById(500, fn ($brokers) => $brokers->each(fn (Customer $b) => $b->notify(Notices::areaRequest($request))));
    }
}

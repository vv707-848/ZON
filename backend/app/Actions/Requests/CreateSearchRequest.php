<?php

namespace App\Actions\Requests;

use App\Actions\Billing\ApplyBoost;
use App\Enums\BoostKind;
use App\Enums\PaymentPurpose;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Jobs\NotifyAreaBrokers;
use App\Models\Customer;
use App\Models\SearchRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * A search request is always saved (a normal one is free). «عاجل» is applied from the plan's balance; without
 * balance the request stays normal and the answer says how to pay for it (the app's «50 ريالاً بعد التأكيد»).
 */
final class CreateSearchRequest
{
    public function __construct(private readonly IndexSync $index, private readonly ApplyBoost $boost) {}

    /**
     * @param  array<string, mixed>  $form
     * @return array{0: SearchRequest, 1: ?array<string, mixed>} the request, and the urgent outcome
     */
    public function __invoke(Customer $customer, array $form): array
    {
        $urgent = (bool) ($form['urgent'] ?? false);
        unset($form['urgent']);

        // the request and its «عاجل» are saved together or not at all: an answer that fails after them would let a
        // retry of the same operation (Idempotency-Key) publish and charge again
        [$request, $outcome] = DB::transaction(function () use ($customer, $form, $urgent) {
            $request = SearchRequest::query()->create([...$form, 'customer_id' => $customer->id, 'urgent' => false]);
            $this->index->request($request);
            $outcome = null;
            if ($urgent) {
                try {
                    ($this->boost)($customer, $request, BoostKind::UrgentRequest);
                    $outcome = ['status' => 'applied'];
                } catch (ApiException) {
                    $outcome = ['status' => 'payment_required', 'purpose' => PaymentPurpose::UrgentRequest->value, 'price' => ApplyBoost::price(BoostKind::UrgentRequest)->riyals(),
                        'target' => ['type' => 'request', 'id' => $request->id]];
                }
            }

            return [$request, $outcome];
        });

        // the brokers' notice follows what is saved: if the queue cannot take it, the request stands (the failure is
        // reported), and its answer is the one a retry gets
        // (Bus::dispatch sends it now; ::dispatch() would send it later, when its PendingDispatch is destroyed,
        // out of reach of rescue)
        rescue(fn () => Bus::dispatch(new NotifyAreaBrokers($request->id)), report: true);

        return [$request->refresh(), $outcome];
    }
}

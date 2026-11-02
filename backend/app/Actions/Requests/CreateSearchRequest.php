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

        $request = DB::transaction(function () use ($customer, $form) {
            $request = SearchRequest::query()->create([...$form, 'customer_id' => $customer->id, 'urgent' => false]);
            $this->index->request($request);

            return $request;
        });

        $outcome = null;
        if ($urgent) {
            try {
                ($this->boost)($customer, $request, BoostKind::UrgentRequest);
                $outcome = ['status' => 'applied'];
            } catch (ApiException $e) {
                $outcome = ['status' => 'payment_required', 'purpose' => PaymentPurpose::UrgentRequest->value, 'price' => ApplyBoost::price(BoostKind::UrgentRequest)->riyals(),
                    'target' => ['type' => 'request', 'id' => $request->id]];
            }
        }
        NotifyAreaBrokers::dispatch($request->id);

        return [$request->refresh(), $outcome];
    }
}

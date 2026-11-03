<?php

namespace App\Integrations\Stores;

/**
 * What a store's notice points at (never trusted as such): the purchase to read back, and, for a refund the store
 * reports on its own (Google's voided purchase), the charge it names.
 */
final readonly class StoreNotice
{
    /**
     * @param  string  $chain  Apple's originalTransactionId · Google's purchaseToken
     * @param  string  $reference  what transaction() reads: Apple's transactionId · Google's purchaseToken
     * @param  ?string  $productId  when the notice says it (Google)
     * @param  ?string  $voidedReference  a charge the store says it refunded (Google's voided purchase: the orderId)
     */
    public function __construct(
        public string $chain,
        public string $reference,
        public ?string $productId = null,
        public ?string $voidedReference = null,
    ) {}
}

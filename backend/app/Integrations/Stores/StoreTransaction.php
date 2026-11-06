<?php

namespace App\Integrations\Stores;

use Carbon\CarbonImmutable;

/**
 * A purchase as the store itself reports it — the only thing ApplyStorePurchase trusts. One charge: a renewal is
 * a new transaction of the same chain.
 */
final readonly class StoreTransaction
{
    /**
     * @param  string  $reference  one charge: Apple's transactionId · Google's orderId
     * @param  string  $chain  the purchase it belongs to: Apple's originalTransactionId · Google's purchaseToken
     * @param  ?CarbonImmutable  $expiresAt  the end of a subscription period
     * @param  ?CarbonImmutable  $revokedAt  refunded or revoked by the store
     * @param  bool  $test  a sandbox, test-card or simulated purchase — no money moved
     * @param  ?string  $accountToken  the account the app named at purchase (Apple appAccountToken · Google obfuscatedExternalAccountId)
     * @param  ?int  $amount  what the customer paid, in the currency's minor unit (halalas for SAR), tax included
     * @param  ?int  $tax  the tax in it, when the store reports it
     * @param  bool  $pending  bought, not paid yet (Google's pending payment)
     * @param  bool  $settled  the store already knows it was delivered (Google: acknowledged or consumed)
     */
    public function __construct(
        public string $store,
        public string $reference,
        public string $chain,
        public string $productId,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $revokedAt,
        public bool $test,
        public ?string $accountToken,
        public ?int $amount,
        public ?string $currency,
        public ?int $tax = null,
        public bool $pending = false,
        public bool $settled = false,
    ) {}
}

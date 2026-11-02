<?php

namespace App\Integrations\Payments;

/** A payment as the provider itself reports it — the only thing ConfirmPayment trusts. */
final readonly class GatewayPayment
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const REFUNDED = 'refunded';

    public function __construct(
        public string $reference,
        public string $status,
        public int $amountHalalas,
        public string $currency,
        public ?string $paymentPublicId,
        public ?string $failureReason = null,
    ) {}
}

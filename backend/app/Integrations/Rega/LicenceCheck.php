<?php

namespace App\Integrations\Rega;

use App\Integrations\IntegrationException;
use Carbon\CarbonImmutable;

/** REGA's answer about a licence (FAL or advertising). */
final readonly class LicenceCheck
{
    public function __construct(
        public bool $valid,
        public ?string $number = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $qrUrl = null,
    ) {}

    /**
     * The expiry of a valid licence. REGA always sends it: an answer without one is a broken integration
     * (INTEGRATION_FAILED with a trace id), never a date the platform makes up.
     */
    public function expiry(): CarbonImmutable
    {
        return $this->expiresAt ?? throw new IntegrationException('rega', 'a valid licence came without its expiry date');
    }
}

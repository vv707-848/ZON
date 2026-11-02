<?php

namespace App\Integrations\Rega;

use Carbon\CarbonImmutable;

/** REGA's answer about a licence (FAL or advertising). */
final readonly class LicenceCheck
{
    public function __construct(
        public bool $valid,
        public ?string $number = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $qrUrl = null,
        public ?string $reason = null,
    ) {}
}

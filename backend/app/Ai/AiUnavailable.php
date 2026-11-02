<?php

namespace App\Ai;

use RuntimeException;

/** Internal: the AI did not answer usably. Never reaches the user — AiGateway turns it into a fallback. */
final class AiUnavailable extends RuntimeException
{
    public const TIMEOUT = 'timeout';

    public const DOWN = 'down';

    public const REJECTED = 'rejected';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct(trim("{$reason} {$detail}"));
    }
}

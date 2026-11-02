<?php

namespace App\Integrations;

use RuntimeException;

/**
 * An external service failed or refused. The API answers INTEGRATION_FAILED without naming the service;
 * the log line carries the service, the reason and the trace id.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(public readonly string $service, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("{$service}: {$reason}", 0, $previous);
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return ['service' => $this->service];
    }
}

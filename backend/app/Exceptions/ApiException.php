<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/** An expected failure with a stable code; bootstrap/app.php renders it in the error envelope. */
class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly ErrorCode $error, public readonly array $details = [], ?string $message = null)
    {
        parent::__construct($message ?? $error->message());
    }

    /** @param array<string, mixed> $details */
    public static function of(ErrorCode $code, array $details = [], ?string $message = null): self
    {
        return new self($code, $details, $message);
    }
}
